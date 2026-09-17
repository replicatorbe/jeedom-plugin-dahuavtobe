#!/usr/bin/env php
<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ---------------------------------------------------------------- garde CLI ---
 * Sur une installation où Apache est en AllowOverride None, les .htaccess sont
 * ignorés et ce fichier est accessible en HTTP : cette garde est la seule
 * protection réelle contre un déclenchement depuis l'extérieur.
 */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

pcntl_async_signals(true);

/* ------------------------------------------------------------------- options */
$opt = getopt('', array('callback:', 'apikey:', 'pid:', 'socketport:', 'loglevel:'));
foreach (array('callback', 'pid', 'socketport') as $required) {
    if (!isset($opt[$required])) {
        fwrite(STDERR, "Argument manquant : --$required\n");
        exit(1);
    }
}
/*
 * La clé API est lue sur STDIN quand elle n'est pas passée en argument : une
 * ligne de commande est visible par tout utilisateur local via `ps`.
 */
if (!isset($opt['apikey'])) {
    $line = fgets(STDIN);
    $opt['apikey'] = ($line === false) ? '' : trim($line);
}
if ($opt['apikey'] === '') {
    fwrite(STDERR, "Clé API absente (--apikey ou première ligne de STDIN)\n");
    exit(1);
}

/* ------------------------------------------------------------------- logging */
class VtoLog {
    const LEVELS = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 99);
    private static $min = 3;

    public static function setLevel($_level) {
        self::$min = isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3;
    }
    public static function write($_level, $_msg) {
        if ((isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3) < self::$min) {
            return;
        }
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] ' . $_msg . PHP_EOL);
    }
    public static function debug($m)   { self::write('debug', $m); }
    public static function info($m)    { self::write('info', $m); }
    public static function warning($m) { self::write('warning', $m); }
    public static function error($m)   { self::write('error', $m); }
}
VtoLog::setLevel(isset($opt['loglevel']) ? $opt['loglevel'] : 'error');

/* =============================================================================
 * Base commune aux deux transports.
 * ========================================================================== */
abstract class VtoTransport {

    /*
     * Événements purement internes au portier, écartés à la source.
     *
     * SIPRegisterResult mérite un mot : le portier le répète toutes les vingt
     * secondes environ, avec un contenu qui varie d'une occurrence à l'autre
     * (le champ Date est parfois là, parfois non). Le laisser passer remplirait
     * l'historique de Jeedom de plusieurs milliers de lignes par jour pour une
     * information qui ne change jamais.
     *
     * CallSnap et AccessSnap, en revanche, ne sont PAS filtrés ici : ce sont des
     * codes d'interphonie et de contrôle d'accès, exactement ce qu'on cherche.
     */
    const BLACKLIST = array(
        'Heartbeat', 'RtspSessionDisconnect', 'TimeChange', 'NTPAdjustTime',
        'SIPRegisterResult', 'ProfileAlarmTransmit', 'IntelliFrame',
        'VideoMotionInfo', 'MDResult', 'SystemState', 'NewFile', 'UpdateFile',
    );

    const MAX_BUFFER = 4194304;      // 4 Mo : au-delà, le flux est corrompu

    /*
     * Certains portiers rejouent leurs événements « Start » encore actifs à
     * chaque abonnement, une seconde après l'attache et horodatés bien avant
     * que la connexion n'existe. Il faut donc savoir depuis quand on écoute.
     */
    const ATTACH_REPLAY_WINDOW = 3;

    public $config;
    public $socket = null;
    public $state = 'disconnected';
    public $connectedAt = 0;

    protected $buffer = '';
    protected $lastActivity = 0;
    private $nextRetry = 0;
    private $failures = 0;
    private $connectFailures = 0;

    public function __construct($_config) {
        $this->config = $_config;
    }

    public function name() {
        return $this->config['name'] . ' (' . $this->config['ip'] . ')';
    }

    public function id() {
        return (int) $this->config['id'];
    }

    abstract public function label();
    abstract public function connect();
    abstract public function readEvents();
    abstract public function tick();

    public function disconnect() {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->state = 'disconnected';
        $this->connectedAt = 0;
        $this->buffer = '';
    }

    /*
     * Cet événement est-il un rejeu de l'abonnement ?
     *
     * À l'attache, le portier annonce les événements « Start » encore en cours.
     * L'intention est raisonnable — il met le nouvel auditeur au courant — mais
     * le résultat ne l'est pas : un appel sans réponse vieux de deux minutes
     * revient en Start à chaque reconnexion, et Jeedom le prend pour un visiteur
     * qui vient d'appuyer. Toutes les reconnexions de la nuit rallumeraient la
     * maison. Le cas est documenté sur VTO2000A, avec des événements rejoués une
     * seconde après l'attache et horodatés avant même que la connexion existe.
     *
     * Seuls les « Start » sont concernés : une impulsion n'est pas un état, elle
     * n'a rien à rejouer. Et la fenêtre est courte — trois secondes — car le
     * risque symétrique existe : une vraie sonnerie pile à la reconnexion serait
     * perdue. Entre manquer une sonnerie survenue dans ces trois secondes-là et
     * en inventer une à chaque coupure réseau, le choix est vite fait.
     */
    public function isAttachReplay($_action) {
        return ($_action == 'Start'
             && $this->connectedAt > 0
             && (time() - $this->connectedAt) <= self::ATTACH_REPLAY_WINDOW);
    }

    public function readyToRetry() {
        return time() >= $this->nextRetry;
    }

    /*
     * Backoff exponentiel avec gigue. Sans la gigue, deux portiers coupés par la
     * même panne réseau se reconnectent à la même seconde.
     */
    public function scheduleRetry($_base, $_reset = false) {
        if ($_reset) {
            $this->failures = 0;
            $this->connectFailures = 0;
            $this->nextRetry = time();
            return 0;
        }
        $this->failures++;
        $delay = min(300, $_base * pow(2, min(6, $this->failures - 1)));
        $delay = (int) ($delay * (0.9 + (mt_rand(0, 200) / 1000)));
        $this->nextRetry = time() + $delay;
        return $delay;
    }

    public function failureCount() {
        return $this->failures;
    }

    /*
     * Compteur distinct de $failures, qui sert au backoff et qu'une déconnexion
     * incrémente déjà avant même la première tentative : s'en servir pour
     * décider du repli ferait changer de transport sur une simple coupure.
     */
    public function noteConnectFailure() {
        return ++$this->connectFailures;
    }

    /* Reprend le rythme du transport remplacé : sinon une bascule remet le
     * backoff à zéro, et un portier mort se fait réinterroger sans fin. */
    public function adoptBackoff($_previous) {
        $this->failures = $_previous->failureCount();
        $this->nextRetry = time();
    }

    /* Ajoute au buffer en le bornant : un flux corrompu ne doit pas remplir la RAM. */
    protected function appendToBuffer($_data) {
        $this->buffer .= $_data;
        $this->lastActivity = time();
        if (strlen($this->buffer) > self::MAX_BUFFER) {
            VtoLog::error($this->name() . ' buffer hors limite, flux considéré comme corrompu');
            $this->buffer = '';
            return false;
        }
        return true;
    }

    /*
     * Met un événement brut au format attendu par Jeedom.
     *
     * Contrairement à un enregistreur, le portier n'a qu'un canal : l'index ne
     * désigne pas une caméra mais une entrée d'alarme ou une porte, et il est
     * transmis tel quel.
     *
     * L'horodatage ne vient JAMAIS de LocaleTime : l'horloge du portier est
     * couramment restée en UTC, et le champ UTC du payload, lui, est explicite.
     */
    protected function normalizeEvent($_code, $_action, $_index, $_data) {
        if (!is_array($_data)) {
            $_data = array();
        }
        $time = time();
        if (isset($_data['UTC']) && is_numeric($_data['UTC'])) {
            $utc = (int) $_data['UTC'];
            /* Une horloge non synchronisée produirait des événements datés de
             * 1970 ou de l'an prochain, que Jeedom rangerait hors de l'histoire.
             * Au-delà de cinq minutes d'écart, on préfère l'heure de réception. */
            if (abs($utc - time()) <= 300) {
                $time = $utc;
            }
        }
        return array(
            'station_id' => $this->id(),
            'code'       => $_code,
            'action'     => ($_action === '' || $_action === null) ? 'Pulse' : $_action,
            'index'      => (int) $_index,
            'data'       => $_data,
            'time'       => date('Y-m-d H:i:s', $time),
        );
    }

    protected function isBlacklisted($_code) {
        return $_code === '' || in_array($_code, self::BLACKLIST, true);
    }
}

/* =============================================================================
 * Transport DHIP : socket binaire, protocole natif Dahua.
 *
 * Sur un portier, DHIP écoute sur le port 5000, et non 37777 comme sur un
 * enregistreur.
 * ========================================================================== */
class VtoDhipTransport extends VtoTransport {

    const MAGIC = "\x20\x00\x00\x00DHIP";
    const MAX_PACKET = 1048576;

    private $requestId = 0;
    private $sessionId = 0;
    private $keepAliveInterval = 60;
    private $lastKeepAliveSent = 0;
    private $awaitingKeepAlive = false;

    public function label() {
        return 'DHIP';
    }

    private function port() {
        return isset($this->config['dhip_port']) && (int) $this->config['dhip_port'] > 0
             ? (int) $this->config['dhip_port'] : 5000;
    }

    private function buildHeader($_length) {
        return self::MAGIC
             . pack('V', $this->sessionId)
             . pack('V', $this->requestId)
             . pack('V', $_length)
             . pack('V', 0)
             . pack('V', $_length)
             . pack('V', 0);
    }

    /*
     * Écriture complète : sur un socket non bloquant fwrite peut n'écrire qu'une
     * partie du tampon, et un en-tête DHIP tronqué désynchronise le flux pour de bon.
     */
    private function send($_payload) {
        if (!is_resource($this->socket)) {
            return false;
        }
        $body = json_encode($_payload);
        $this->requestId++;
        $data = $this->buildHeader(strlen($body)) . $body;

        $total = strlen($data);
        $sent = 0;
        $deadline = microtime(true) + 2;
        while ($sent < $total) {
            $written = @fwrite($this->socket, substr($data, $sent));
            if ($written === false) {
                return false;
            }
            if ($written === 0) {
                if (microtime(true) > $deadline) {
                    VtoLog::warning($this->name() . ' écriture bloquée');
                    return false;
                }
                $read = null; $write = array($this->socket); $except = null;
                @stream_select($read, $write, $except, 1);
                continue;
            }
            $sent += $written;
        }
        return true;
    }

    /* Extrait du buffer tous les paquets DHIP complets. */
    private function drainPackets() {
        $packets = array();
        while (strlen($this->buffer) >= 32) {
            if (substr($this->buffer, 0, 8) !== self::MAGIC) {
                $next = strpos($this->buffer, self::MAGIC, 1);
                if ($next === false) {
                    $this->buffer = '';
                    VtoLog::warning($this->name() . ' flux désynchronisé, buffer vidé');
                    break;
                }
                $this->buffer = substr($this->buffer, $next);
                continue;
            }
            $length = unpack('V', substr($this->buffer, 16, 4))[1];
            /* Une longueur aberrante vient d'un faux en-tête. Sans cette borne,
             * le buffer ne serait plus jamais purgé. */
            if ($length < 0 || $length > self::MAX_PACKET) {
                VtoLog::warning($this->name() . ' longueur DHIP aberrante (' . $length . ')');
                $next = strpos($this->buffer, self::MAGIC, 1);
                $this->buffer = ($next === false) ? '' : substr($this->buffer, $next);
                continue;
            }
            if (strlen($this->buffer) < 32 + $length) {
                break;                              // paquet incomplet, on attend la suite
            }
            $packets[] = substr($this->buffer, 32, $length);
            $this->buffer = substr($this->buffer, 32 + $length);
        }
        return $packets;
    }

    /*
     * Lecture utilisée pendant la connexion. Elle BOUCLE : fread plafonne à la
     * taille de chunk du flux et une réponse peut arriver fragmentée.
     */
    private function readPackets($_timeout = 6) {
        $deadline = microtime(true) + $_timeout;
        while (true) {
            $packets = $this->drainPackets();
            if (!empty($packets)) {
                return $packets;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return array();
            }
            $read = array($this->socket); $write = null; $except = null;
            if (@stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1000000)) < 1) {
                return array();
            }
            $data = @fread($this->socket, 65535);
            if ($data === '' || $data === false) {
                return false;
            }
            if (!$this->appendToBuffer($data)) {
                return false;
            }
        }
    }

    public function connect() {
        $this->disconnect();
        $this->requestId = 0;
        $this->sessionId = 0;

        $errno = 0; $errstr = '';
        $this->socket = @stream_socket_client(
            'tcp://' . $this->config['ip'] . ':' . $this->port(), $errno, $errstr, 8
        );
        if ($this->socket === false) {
            $this->socket = null;
            return array(false, 'connexion TCP impossible : ' . $errstr);
        }
        stream_set_timeout($this->socket, 10);

        list($ok, $error) = $this->login();
        if (!$ok) {
            $this->disconnect();
            return array(false, $error);
        }
        if (!$this->attachEvents()) {
            $this->disconnect();
            return array(false, 'souscription aux événements refusée');
        }

        stream_set_blocking($this->socket, false);
        $this->state = 'connected';
        $this->connectedAt = time();
        $this->lastKeepAliveSent = time();
        $this->lastActivity = time();
        $this->awaitingKeepAlive = false;
        return array(true, '');
    }

    /*
     * Authentification en deux temps : la première requête est volontairement
     * rejetée, le portier renvoie alors le sel (random) et le realm du challenge.
     */
    private function login() {
        if (!$this->send(array(
            'id'      => 10000,
            'magic'   => '0x1234',
            'method'  => 'global.login',
            'session' => 0,
            'params'  => array(
                'clientType' => '',
                'ipAddr'     => '(null)',
                'loginType'  => 'Direct',
                'password'   => '',
                'userName'   => $this->config['username'],
            ),
        ))) {
            return array(false, 'envoi du challenge impossible');
        }
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return array(false, 'aucune réponse au challenge de connexion');
        }
        $challenge = json_decode($packets[0], true);
        if (!isset($challenge['params']['random'], $challenge['params']['realm'])) {
            return array(false, 'challenge de connexion illisible');
        }

        $this->sessionId = isset($challenge['session']) ? $challenge['session'] : 0;
        $hash = strtoupper(md5(
            $this->config['username'] . ':' . $challenge['params']['random'] . ':' .
            strtoupper(md5($this->config['username'] . ':' . $challenge['params']['realm'] . ':' . $this->config['password']))
        ));

        if (!$this->send(array(
            'id'      => 10000,
            'magic'   => '0x1234',
            'method'  => 'global.login',
            'session' => $this->sessionId,
            'params'  => array(
                'userName'      => $this->config['username'],
                'password'      => $hash,
                'clientType'    => '',
                'ipAddr'        => '(null)',
                'loginType'     => 'Direct',
                'authorityType' => 'Default',
            ),
        ))) {
            return array(false, 'envoi de l\'authentification impossible');
        }
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return array(false, 'aucune réponse à l\'authentification');
        }
        $result = json_decode($packets[0], true);

        if (empty($result['result'])) {
            return array(false, self::describeError($result));
        }
        if (isset($result['params']['keepAliveInterval'])) {
            $this->keepAliveInterval = max(10, (int) $result['params']['keepAliveInterval']);
        }
        return array(true, '');
    }

    /* Traduit les codes d'erreur Dahua en message exploitable. */
    private static function describeError($_result) {
        $code = isset($_result['error']['code']) ? $_result['error']['code'] : 0;
        // 268632079 est le challenge d'authentification : il n'apparaît jamais ici.
        $known = array(
            268632080 => 'utilisateur inconnu ou mot de passe incorrect',
            268632081 => 'compte verrouillé après trop de tentatives',
            268632082 => 'compte bloqué ou désactivé',
            268632083 => 'compte déjà connecté depuis un autre poste',
            268632086 => 'équipement non initialisé',
            268894210 => 'permissions insuffisantes pour cet utilisateur',
            287637505 => 'session invalide ou expirée',
        );
        if (isset($known[$code])) {
            return $known[$code] . ' (code ' . $code . ')';
        }
        return isset($_result['error']['message'])
            ? $_result['error']['message'] . ' (code ' . $code . ')'
            : 'authentification refusée';
    }

    private function attachEvents() {
        if (!$this->send(array(
            'id'      => $this->requestId,
            'magic'   => '0x1234',
            'method'  => 'eventManager.attach',
            'session' => $this->sessionId,
            'params'  => array('codes' => array('All')),
        ))) {
            return false;
        }
        $packets = $this->readPackets();
        if ($packets === false || empty($packets)) {
            return false;
        }
        $result = json_decode($packets[0], true);
        return !empty($result['result']);
    }

    public function readEvents() {
        $data = @fread($this->socket, 65535);
        if ($data === '' || $data === false) {
            return false;
        }
        if (!$this->appendToBuffer($data)) {
            return false;
        }

        $events = array();
        foreach ($this->drainPackets() as $packet) {
            $message = json_decode($packet, true);
            if (!is_array($message)) {
                continue;
            }
            if (isset($message['result'])) {
                $this->awaitingKeepAlive = false;       // réponse au keepAlive
                continue;
            }
            if (!isset($message['method']) || $message['method'] != 'client.notifyEventStream') {
                continue;
            }
            if (!isset($message['params']['eventList']) || !is_array($message['params']['eventList'])) {
                continue;
            }
            foreach ($message['params']['eventList'] as $raw) {
                $code = isset($raw['Code']) ? $raw['Code'] : '';
                if ($this->isBlacklisted($code)) {
                    continue;
                }
                $events[] = $this->normalizeEvent(
                    $code,
                    isset($raw['Action']) ? $raw['Action'] : 'Pulse',
                    isset($raw['Index']) ? $raw['Index'] : 0,
                    isset($raw['Data']) ? $raw['Data'] : array()
                );
            }
        }
        return $events;
    }

    /* Le portier ferme la session si aucun keepAlive n'arrive dans l'intervalle
     * annoncé. On émet à la moitié de l'intervalle, pour laisser de la marge. */
    public function tick() {
        $period = max(5, (int) ($this->keepAliveInterval / 2));
        if (time() - $this->lastKeepAliveSent < $period) {
            return true;
        }
        if ($this->awaitingKeepAlive) {
            VtoLog::warning($this->name() . ' keepAlive sans réponse');
            return false;
        }
        if (!$this->send(array(
            'id'      => $this->requestId,
            'magic'   => '0x1234',
            'method'  => 'global.keepAlive',
            'session' => $this->sessionId,
            'params'  => array('timeout' => $this->keepAliveInterval, 'active' => true),
        ))) {
            return false;
        }
        $this->lastKeepAliveSent = time();
        $this->awaitingKeepAlive = true;
        return true;
    }
}

/* =============================================================================
 * Transport CGI : long-polling HTTP multipart.
 *
 * La requête HTTP est écrite à la main sur un socket brut plutôt que confiée à
 * curl : le flux reste ainsi sélectionnable par la boucle principale, au lieu
 * d'immobiliser un curl_exec sans fin.
 * ========================================================================== */
class VtoCgiTransport extends VtoTransport {

    private $boundary = 'myboundary';
    private $heartbeat = 10;

    public function label() {
        return 'CGI';
    }

    /*
     * Relu à chaque connexion et non une fois pour toutes à la construction :
     * la configuration d'un client est remplacée à chaud quand l'utilisateur
     * change un réglage, et un battement figé au démarrage du démon serait resté
     * faux jusqu'au prochain redémarrage.
     */
    private function refreshHeartbeat() {
        if (isset($this->config['heartbeat']) && (int) $this->config['heartbeat'] > 0) {
            $this->heartbeat = max(3, min(60, (int) $this->config['heartbeat']));
        }
    }

    private function httpPort() {
        return isset($this->config['http_port']) && (int) $this->config['http_port'] > 0
             ? (int) $this->config['http_port'] : 80;
    }

    public function connect() {
        $this->disconnect();
        $this->refreshHeartbeat();

        /*
         * heartbeat n'est pas un confort : sans lui, le portier n'envoie rien
         * entre deux visiteurs, et une journée calme devient indiscernable d'une
         * socket morte. Avec, un silence de trois battements condamne la liaison.
         *
         * codes=[All] plutôt qu'une liste : le portier accepte n'importe quel
         * code sans le valider — un abonnement ciblé qui « marche » ne prouverait
         * donc rien, et les codes varient d'un micrologiciel à l'autre.
         */
        $path = '/cgi-bin/eventManager.cgi?action=attach&codes=%5BAll%5D&heartbeat=' . $this->heartbeat;

        // Première requête : elle sert uniquement à récupérer le challenge Digest.
        list($status, $headers, $rest, $socket) = $this->request($path, null);
        if ($status === 0) {
            return array(false, $rest);            // $rest porte le message d'erreur
        }
        if ($status == 200) {
            return $this->startStream($socket, $headers, $rest);
        }
        if (is_resource($socket)) {
            @fclose($socket);
        }
        if ($status != 401) {
            return array(false, 'réponse HTTP inattendue : ' . $status);
        }

        $challenge = $this->parseDigestChallenge($headers);
        if ($challenge === false) {
            return array(false, 'challenge Digest illisible');
        }

        list($status, $headers, $rest, $socket) = $this->request($path, $challenge);
        if ($status === 0) {
            return array(false, $rest);
        }
        if ($status == 401) {
            if (is_resource($socket)) { @fclose($socket); }
            return array(false, 'utilisateur inconnu ou mot de passe incorrect');
        }
        if ($status != 200) {
            if (is_resource($socket)) { @fclose($socket); }
            return array(false, 'réponse HTTP inattendue : ' . $status);
        }
        return $this->startStream($socket, $headers, $rest);
    }

    private function startStream($_socket, $_headers, $_rest) {
        if (preg_match('/boundary=([^\s;]+)/i', $_headers, $m)) {
            $this->boundary = trim($m[1], "\"' \t\r\n");
        }
        $this->socket = $_socket;
        stream_set_blocking($this->socket, false);
        $this->buffer = $_rest;
        $this->state = 'connected';
        $this->connectedAt = time();
        $this->lastActivity = time();
        return array(true, '');
    }

    /*
     * Envoie une requête GET et lit uniquement les en-têtes de la réponse.
     * Retourne [status, en-têtes bruts, début du corps déjà lu, socket].
     */
    private function request($_path, $_challenge) {
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $this->config['ip'] . ':' . $this->httpPort(), $errno, $errstr, 8
        );
        if ($socket === false) {
            return array(0, '', 'connexion TCP impossible : ' . $errstr, null);
        }
        stream_set_timeout($socket, 10);

        $request  = "GET " . $_path . " HTTP/1.1\r\n";
        $request .= "Host: " . $this->config['ip'] . ':' . $this->httpPort() . "\r\n";
        $request .= "Accept: multipart/x-mixed-replace\r\n";
        if ($_challenge !== null) {
            $request .= 'Authorization: ' . $this->buildDigestHeader($_challenge, $_path) . "\r\n";
        }
        $request .= "Connection: keep-alive\r\n\r\n";

        if (@fwrite($socket, $request) === false) {
            @fclose($socket);
            return array(0, '', 'envoi de la requête impossible', null);
        }

        // Lecture des en-têtes jusqu'à la ligne vide.
        $headers = '';
        $deadline = microtime(true) + 10;
        while (strpos($headers, "\r\n\r\n") === false) {
            if (microtime(true) > $deadline) {
                @fclose($socket);
                return array(0, '', 'délai dépassé en attente des en-têtes', null);
            }
            $chunk = @fread($socket, 2048);
            if ($chunk === '' || $chunk === false) {
                @fclose($socket);
                return array(0, '', 'connexion fermée pendant les en-têtes', null);
            }
            $headers .= $chunk;
        }
        $split = strpos($headers, "\r\n\r\n");
        $rest = substr($headers, $split + 4);
        $headers = substr($headers, 0, $split);

        $status = 0;
        if (preg_match('#^HTTP/1\.[01]\s+(\d+)#', $headers, $m)) {
            $status = (int) $m[1];
        }
        return array($status, $headers, $rest, $socket);
    }

    private function parseDigestChallenge($_headers) {
        if (!preg_match('/WWW-Authenticate:\s*Digest\s*(.+)/i', $_headers, $m)) {
            return false;
        }
        $challenge = array();
        if (preg_match_all('/(\w+)=(?:"([^"]*)"|([^,\s]+))/', $m[1], $parts, PREG_SET_ORDER)) {
            foreach ($parts as $p) {
                $challenge[strtolower($p[1])] = ($p[2] !== '') ? $p[2] : (isset($p[3]) ? $p[3] : '');
            }
        }
        return isset($challenge['nonce']) ? $challenge : false;
    }

    /*
     * Digest MD5 (RFC 2617). L'URI hachée doit être identique, caractère pour
     * caractère, à celle de la ligne de requête — crochets encodés compris.
     */
    private function buildDigestHeader($_challenge, $_uri) {
        $user  = $this->config['username'];
        $pass  = $this->config['password'];
        $realm = isset($_challenge['realm']) ? $_challenge['realm'] : '';
        $nonce = $_challenge['nonce'];
        $qop   = isset($_challenge['qop']) ? $_challenge['qop'] : '';

        $ha1 = md5($user . ':' . $realm . ':' . $pass);
        $ha2 = md5('GET:' . $_uri);

        $header = 'Digest username="' . $user . '", realm="' . $realm . '", nonce="' . $nonce
                . '", uri="' . $_uri . '"';

        if ($qop !== '') {
            $cnonce = bin2hex(random_bytes(8));
            $nc = '00000001';
            $response = md5($ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':auth:' . $ha2);
            $header .= ', qop=auth, nc=' . $nc . ', cnonce="' . $cnonce . '"';
        } else {
            $response = md5($ha1 . ':' . $nonce . ':' . $ha2);
        }
        $header .= ', response="' . $response . '"';
        if (isset($_challenge['opaque'])) {
            $header .= ', opaque="' . $_challenge['opaque'] . '"';
        }
        return $header;
    }

    public function readEvents() {
        $data = @fread($this->socket, 65535);
        if ($data === '' || $data === false) {
            return false;
        }
        if (!$this->appendToBuffer($data)) {
            return false;
        }

        $events = array();
        $separator = '--' . $this->boundary;

        /*
         * Un bloc n'est complet que lorsque le séparateur SUIVANT est arrivé.
         * On découpe sur la frontière et non sur Content-Length : cet en-tête ne
         * compte pas le retour à la ligne final, et s'y fier décale le parsing
         * d'un bloc à l'autre.
         */
        while (($pos = strpos($this->buffer, $separator, 1)) !== false) {
            $block = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos);

            $event = $this->parseBlock($block);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        return $events;
    }

    private function parseBlock($_block) {
        // Sépare les en-têtes du bloc de son corps.
        $body = $_block;
        if (($p = strpos($_block, "\r\n\r\n")) !== false) {
            $body = substr($_block, $p + 4);
        } elseif (($p = strpos($_block, "\n\n")) !== false) {
            $body = substr($_block, $p + 2);
        }
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        /*
         * Format : Code=AccessControl;action=Pulse;index=0;data={ ... }
         *
         * index ET data sont facultatifs dans cette expression, et c'est
         * délibéré : exiger l'un ou l'autre ferait rejeter l'événement ENTIER
         * chez un modèle qui l'omet. Perdre une sonnerie parce qu'il manquait un
         * numéro de canal dont on ne fait rien serait absurde — le portier n'a
         * de toute façon qu'un seul canal.
         */
        if (!preg_match('/Code=([^;\s]+)\s*;\s*action=([^;\s]+)(?:\s*;\s*index=(-?\d+))?(?:\s*;\s*data=(.*))?$/s', $body, $m)) {
            return null;
        }
        $code = $m[1];
        if ($this->isBlacklisted($code)) {
            return null;
        }
        $index = (isset($m[3]) && $m[3] !== '') ? $m[3] : 0;
        $data = array();
        if (isset($m[4]) && trim($m[4]) !== '') {
            $decoded = json_decode(trim($m[4]), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return $this->normalizeEvent($code, $m[2], $index, $data);
    }

    /* Rien à émettre : c'est le portier qui pousse son battement. Un silence
     * prolongé signifie que la connexion est morte, même sans FIN ni RST. */
    public function tick() {
        if (time() - $this->lastActivity > $this->heartbeat * 3) {
            VtoLog::warning($this->name() . ' aucun battement depuis ' . ($this->heartbeat * 3) . 's');
            return false;
        }
        return true;
    }
}

/* =============================================================================
 * Démon.
 * ========================================================================== */
class VtoDaemon {

    const PUSH_TIMEOUT   = 4;        // un Jeedom lent ne doit jamais geler la boucle
    const PUSH_ATTEMPTS  = 2;
    const PUSH_QUEUE_MAX = 500;

    const SNAPSHOT_TIMEOUT = 10;

    /* Une capture au plus toutes les N secondes par portier : un visiteur qui
     * s'acharne sur le bouton ne doit pas faire naître dix processus. */
    const SNAPSHOT_MIN_INTERVAL = 5;

    private $opt;
    private $config = array();
    private $clients = array();          // id d'équipement => VtoTransport
    private $listener = null;
    private $pulseResets = array();
    private $lastSnapshot = array();
    private $pushQueue = array();
    private $pushPid = 0;
    private $reloadPending = false;
    private $running = true;

    public function __construct($_opt) {
        $this->opt = $_opt;
    }

    public function stop() {
        $this->running = false;
    }

    /* ------------------------------------------------------------- callback */

    private function callback($_query = '', $_body = null) {
        $url = $this->opt['callback'] . '?apikey=' . urlencode($this->opt['apikey']) . $_query;

        for ($i = 0; $i < self::PUSH_ATTEMPTS; $i++) {
            $ch = curl_init($url);
            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => self::PUSH_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            );
            if ($_body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($_body);
                $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
            }
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($code == 200) {
                /*
                 * Un 200 ne suffit pas pour un lot d'événements : le callback
                 * répond aussi en 200 quand il le rejette. Seul 'OK' atteste que
                 * Jeedom l'a réellement traité — sans ce test, une clé API
                 * régénérée pendant que le démon tourne ferait jeter tous les
                 * événements en les lui faisant croire livrés.
                 */
                if ($_body === null || trim((string) $response) === 'OK') {
                    return $response;
                }
                VtoLog::error('lot refusé par Jeedom : ' . substr(trim((string) $response), 0, 200)
                            . ' — tentative ' . ($i + 1) . '/' . self::PUSH_ATTEMPTS);
            } else {
                VtoLog::error('callback en échec (HTTP ' . $code . ($error != '' ? ' / ' . $error : '')
                            . ') tentative ' . ($i + 1) . '/' . self::PUSH_ATTEMPTS);
            }
        }
        return false;
    }

    private function loadConfig() {
        $raw = $this->callback('&action=config');
        if ($raw === false) {
            VtoLog::error('configuration illisible depuis Jeedom');
            return false;
        }
        $config = json_decode($raw, true);
        if (!is_array($config) || !isset($config['stations'])) {
            /*
             * 80 caractères et pas davantage : cette charge utile contient le
             * mot de passe du portier. Ce qu'on veut lire ici, c'est le début de
             * la réponse — une page d'erreur HTML ou du JSON tronqué se
             * reconnaissent bien avant.
             */
            VtoLog::error('configuration invalide : ' . substr((string) $raw, 0, 80));
            return false;
        }
        $config['reconnect_delay']  = isset($config['reconnect_delay']) ? (int) $config['reconnect_delay'] : 15;
        $config['snapshot_keep']    = isset($config['snapshot_keep']) ? (int) $config['snapshot_keep'] : 50;
        $config['heartbeat']        = isset($config['heartbeat']) ? (int) $config['heartbeat'] : 10;
        /* Liste vide : aucune capture. Le réglage est traduit en liste côté
         * Jeedom, le démon n'a pas à savoir pourquoi un code déclenche une photo. */
        if (!isset($config['shot_codes']) || !is_array($config['shot_codes'])) {
            $config['shot_codes'] = array();
        }
        /* Codes décrivant un état : le démon ne leur fabrique aucune fin. */
        if (!isset($config['hold_codes']) || !is_array($config['hold_codes'])) {
            $config['hold_codes'] = array();
        }
        $config['pulse_duration'] = isset($config['pulse_duration'])
                                  ? max(1, (int) $config['pulse_duration']) : 5;
        $this->config = $config;

        /*
         * Sans cela le démon daterait ses événements en UTC — il ne charge pas
         * le coeur de Jeedom, donc jamais son date_default_timezone_set — alors
         * que Jeedom les enregistre en heure locale : deux heures d'écart en été.
         */
        if (!empty($config['timezone']) && in_array($config['timezone'], timezone_identifiers_list(), true)) {
            date_default_timezone_set($config['timezone']);
        }

        $keep = array();
        foreach ($config['stations'] as $station) {
            $station['heartbeat'] = $config['heartbeat'];
            $keep[(int) $station['id']] = $station;
        }
        foreach ($this->clients as $id => $client) {
            if (!isset($keep[$id])) {
                VtoLog::info($client->name() . ' retiré de la configuration');
                $client->disconnect();
                unset($this->clients[$id]);
            }
        }
        foreach ($keep as $id => $stationConfig) {
            if (isset($this->clients[$id])) {
                $previous = $this->clients[$id]->config;
                $this->clients[$id]->config = $stationConfig;
                if ($previous['ip'] != $stationConfig['ip']
                 || $previous['username'] != $stationConfig['username']
                 || $previous['password'] != $stationConfig['password']
                 || $previous['transport'] != $stationConfig['transport']
                 || $previous['http_port'] != $stationConfig['http_port']
                 || $previous['dhip_port'] != $stationConfig['dhip_port']
                 /* Le battement fait partie de l'URL d'abonnement : il ne peut
                  * changer qu'en rouvrant le flux. */
                 || $previous['heartbeat'] != $stationConfig['heartbeat']) {
                    VtoLog::info($this->clients[$id]->name() . ' paramètres modifiés, reconnexion');
                    $this->clients[$id]->disconnect();
                    $this->clients[$id] = $this->makeTransport($stationConfig);
                }
            } else {
                $this->clients[$id] = $this->makeTransport($stationConfig);
            }
        }
        $this->lastSnapshot = array_intersect_key($this->lastSnapshot, $keep);

        VtoLog::info(count($this->clients) . ' portier(s) configuré(s)');
        return true;
    }

    /*
     * Choisit le transport. En automatique on commence par le CGI, contrairement
     * au plugin des enregistreurs : c'est celui dont on sait qu'il porte les
     * événements du portier, DHIP restant le repli.
     */
    private function makeTransport($_stationConfig, $_prefer = null) {
        $mode = isset($_stationConfig['transport']) ? $_stationConfig['transport'] : 'auto';
        if ($mode == 'cgi') {
            return new VtoCgiTransport($_stationConfig);
        }
        if ($mode == 'dhip') {
            return new VtoDhipTransport($_stationConfig);
        }
        return ($_prefer == 'DHIP') ? new VtoDhipTransport($_stationConfig)
                                    : new VtoCgiTransport($_stationConfig);
    }

    /* --------------------------------------------------------------- démarrage */

    public function run() {
        if (trim((string) $this->callback('&test=1')) !== 'OK') {
            VtoLog::error('callback injoignable : ' . $this->opt['callback']);
            return 1;
        }
        if (!$this->loadConfig()) {
            return 1;
        }

        $port = (int) $this->opt['socketport'];
        $errno = 0; $errstr = '';
        $this->listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($this->listener === false) {
            VtoLog::error('écoute impossible sur le port ' . $port . ' : ' . $errstr);
            return 1;
        }
        stream_set_blocking($this->listener, false);

        file_put_contents($this->opt['pid'], getmypid() . "\n");
        VtoLog::info('démon démarré (pid ' . getmypid() . ', port ' . $port . ')');

        $this->loop();

        VtoLog::info('arrêt du démon');
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        @unlink($this->opt['pid']);
        return 0;
    }

    private function loop() {
        while ($this->running) {
            $this->reapChildren();
            $this->drainPushQueue();

            if ($this->reloadPending) {
                $this->reloadPending = false;
                $this->loadConfig();
            }
            $this->connectPending();

            $read = array($this->listener);
            foreach ($this->clients as $client) {
                if ($client->state == 'connected' && is_resource($client->socket)) {
                    $read[] = $client->socket;
                }
            }
            $write = null; $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    if ($stream === $this->listener) {
                        $this->acceptOrders();
                    } else {
                        $this->handleStream($stream);
                    }
                }
            }

            $this->flushPulseResets();
            $this->ticks();
        }
    }

    /* Une seule tentative de connexion par tour de boucle. */
    private function connectPending() {
        foreach ($this->clients as $id => $client) {
            if ($client->state == 'connected' || !$client->readyToRetry()) {
                continue;
            }
            VtoLog::info($client->name() . ' connexion (' . $client->label() . ')…');
            list($ok, $error) = $client->connect();
            if ($ok) {
                VtoLog::info($client->name() . ' connecté en ' . $client->label());
                $client->scheduleRetry(0, true);
                $this->push(array(array(
                    'station_id' => $client->id(),
                    'type'       => 'status',
                    'status'     => 'connected',
                    'transport'  => $client->label(),
                    'time'       => date('Y-m-d H:i:s'),
                )));
                $this->refreshDoorStatus($client);
                return;
            }

            $first = ($client->failureCount() == 0);
            $connectFailures = $client->noteConnectFailure();
            $delay = $client->scheduleRetry(max(5, (int) $this->config['reconnect_delay']));
            VtoLog::error($client->name() . ' ' . $error . ' — nouvel essai dans ' . $delay . 's');

            /*
             * Bascule automatique de transport, dans les deux sens : certains
             * micrologiciels refusent l'un tout en servant parfaitement l'autre,
             * et un repli à sens unique condamnerait le portier au transport de
             * secours jusqu'au prochain redémarrage du démon.
             */
            $mode = isset($client->config['transport']) ? $client->config['transport'] : 'auto';
            if ($mode == 'auto' && $connectFailures >= 2) {
                $other = ($client->label() == 'DHIP') ? 'CGI' : 'DHIP';
                VtoLog::warning($client->name() . ' ' . $client->label()
                              . ' en échec, bascule sur le transport ' . $other);
                $this->clients[$id] = $this->makeTransport($client->config, $other);
                $this->clients[$id]->adoptBackoff($client);
            }

            // Un seul signalement de déconnexion, pas un à chaque retentative.
            if ($first) {
                $this->push(array(array(
                    'station_id' => $client->id(),
                    'type'       => 'status',
                    'status'     => 'disconnected',
                    'error'      => $error,
                    'time'       => date('Y-m-d H:i:s'),
                )));
            }
            return;
        }
    }

    private function handleStream($_stream) {
        foreach ($this->clients as $client) {
            if ($client->socket !== $_stream) {
                continue;
            }
            $events = $client->readEvents();
            if ($events === false) {
                $this->dropClient($client, 'connexion fermée par le portier');
                return;
            }
            if (!empty($events)) {
                $this->dispatchEvents($client, $events);
            }
            return;
        }
    }

    private function dropClient($_client, $_reason) {
        VtoLog::warning($_client->name() . ' ' . $_reason);
        $_client->disconnect();
        $_client->scheduleRetry(max(5, (int) $this->config['reconnect_delay']));
        $this->push(array(array(
            'station_id' => $_client->id(),
            'type'       => 'status',
            'status'     => 'disconnected',
            'error'      => $_reason,
            'time'       => date('Y-m-d H:i:s'),
        )));
    }

    private function dispatchEvents($_client, $_events) {
        $batch = array();
        $shot = false;

        foreach ($_events as $event) {
            VtoLog::debug($_client->name() . ' ' . $event['code'] . ' ' . $event['action']
                        . ' index ' . $event['index']
                        . (empty($event['data']) ? '' : ' ' . json_encode($event['data'])));

            if ($_client->isAttachReplay($event['action'])) {
                VtoLog::info($_client->name() . ' ' . $event['code']
                           . ' ignoré : rejeu de l\'abonnement, pas un nouvel événement');
                continue;
            }
            $batch[] = $event;

            $key = $event['station_id'] . '|' . $event['code'];
            if ($event['action'] == 'Pulse' && !in_array($event['code'], $this->config['hold_codes'], true)) {
                /*
                 * Événement sans fin annoncée : c'est le démon qui la produira.
                 * Sauf pour les codes d'état, dont l'impulsion décrit un
                 * changement durable — une porte qu'on vient d'ouvrir reste
                 * ouverte, et lui fabriquer une fin la déclarerait refermée.
                 */
                $this->pulseResets[$key] = time() + (int) $this->config['pulse_duration'];
            } else {
                /* Start ou Stop : le portier gère lui-même la fin, une remise à
                 * zéro différée écraserait un état légitime. */
                unset($this->pulseResets[$key]);
            }

            if ($event['action'] != 'Stop' && $this->isShot($event)) {
                $shot = true;
            }
        }

        if (!empty($batch)) {
            $this->push($batch);
        }
        if ($shot) {
            $this->maybeSnapshot($_client);
        }
    }

    /*
     * Cet événement mérite-t-il une photo ?
     *
     * La question n'est pas seulement « quel code » : le même code sert à
     * annoncer le début de l'appel et sa fin, et seul un champ de la charge
     * utile les distingue. Photographier sur la fin, c'est garder l'image d'un
     * seuil vide en croyant tenir celle du visiteur.
     *
     * Une condition qui ne peut pas être évaluée — champ absent — répond non :
     * mieux vaut pas de photo qu'une mauvaise, et l'onglet Diagnostic de Jeedom
     * montrera de toute façon l'événement tel qu'il est arrivé.
     */
    private function isShot($_event) {
        foreach ($this->config['shot_codes'] as $rule) {
            if (!is_array($rule) || !isset($rule['code']) || $rule['code'] !== $_event['code']) {
                continue;
            }
            if (!isset($rule['field'])) {
                return true;
            }
            $field = $rule['field'];
            if (!isset($_event['data'][$field]) || !isset($rule['values']) || !is_array($rule['values'])) {
                return false;
            }
            return in_array((int) $_event['data'][$field], array_map('intval', $rule['values']), true);
        }
        return false;
    }

    /*
     * Relève l'état réel de la gâche à la connexion.
     *
     * Le portier n'annonce que les CHANGEMENTS d'état : au démarrage du démon,
     * personne ne sait où en est la porte, et la commande resterait à sa valeur
     * d'usine — « fermée » — sans que rien ne l'ait vérifié. Sur une porte
     * d'entrée, c'est le genre d'affirmation qu'il vaut mieux ne pas inventer.
     *
     * Dans un fils, comme les captures : la boucle ne doit jamais attendre une
     * requête HTTP, les sessions du portier expireraient.
     */
    private function refreshDoorStatus($_client) {
        $pid = pcntl_fork();
        if ($pid == -1) {
            VtoLog::error('fork impossible pour l\'état de la porte');
            return;
        }
        if ($pid > 0) {
            return;
        }

        // --- processus fils ---
        $this->closeInheritedSockets();
        $open = $this->fetchDoorStatus($_client->config);
        if ($open !== null) {
            $this->callback('', array('events' => array(array(
                'station_id' => $_client->id(),
                'type'       => 'door',
                'open'       => $open,
                'time'       => date('Y-m-d H:i:s'),
            ))));
        }
        exit(0);
    }

    /* Rend true, false, ou null quand le portier n'a pas su répondre — et null
     * n'est pas false : on préfère ne rien dire à dire « fermée » sans l'avoir vu. */
    private function fetchDoorStatus($_stationConfig) {
        $httpPort = isset($_stationConfig['http_port']) && (int) $_stationConfig['http_port'] > 0
                  ? (int) $_stationConfig['http_port'] : 80;
        $channel = isset($_stationConfig['channel']) ? (int) $_stationConfig['channel'] : 1;
        $url = 'http://' . $_stationConfig['ip'] . ':' . $httpPort
             . '/cgi-bin/accessControl.cgi?action=getDoorStatus&channel=' . $channel;

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $_stationConfig['username'] . ':' . $_stationConfig['password'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code != 200) {
            VtoLog::warning('état de la porte illisible (HTTP ' . $code . ')');
            return null;
        }
        if (preg_match('/status\s*=\s*(\w+)/i', $body, $m)) {
            return (strtolower($m[1]) == 'open');
        }
        VtoLog::warning('réponse inattendue à l\'état de la porte : ' . substr(trim($body), 0, 60));
        return null;
    }

    /* ----------------------------------------------------------- captures */

    /*
     * La photo est prise ici, dans le démon, et non par un scénario Jeedom :
     * entre l'appui sur le bouton et le réveil d'un scénario il se passe assez
     * de temps pour que le visiteur ait reculé d'un pas.
     */
    private function maybeSnapshot($_client) {
        $id = $_client->id();
        if (isset($this->lastSnapshot[$id]) && time() - $this->lastSnapshot[$id] < self::SNAPSHOT_MIN_INTERVAL) {
            VtoLog::debug($_client->name() . ' capture ignorée (trop rapprochée)');
            return;
        }
        /* Horodaté AVANT le fork : c'est la seule protection contre
         * l'empilement, le fils n'étant volontairement pas suivi. */
        $this->lastSnapshot[$id] = time();

        $pid = pcntl_fork();
        if ($pid == -1) {
            VtoLog::error('fork impossible pour la capture');
            return;
        }
        if ($pid > 0) {
            /*
             * Le fils n'est pas suivi : reapChildren() fait un waitpid(-1) et
             * récolte n'importe quel fils, donc un waitpid ciblé retournerait -1
             * et serait pris pour « encore en cours ».
             */
            return;
        }

        // --- processus fils ---
        $this->closeInheritedSockets();
        $shot = $this->fetchSnapshot($_client->config);

        /*
         * Posté dans les deux cas, échec compris. Une capture ratée laisse la
         * commande Image sur la photo de la VISITE PRÉCÉDENTE, et rien ne
         * distingue alors le visage d'hier de celui qui attend derrière la
         * porte. Le dire est le minimum : Jeedom pose un avertissement au
         * journal et l'événement figure dans l'onglet Diagnostic.
         */
        $event = array(
            'station_id' => $id,
            'type'       => 'snapshot',
            'ok'         => ($shot !== false),
            'time'       => date('Y-m-d H:i:s'),
        );
        if ($shot !== false) {
            $event['file'] = $shot;
        }
        $this->callback('', array('events' => array($event)));
        exit(0);
    }

    private function fetchSnapshot($_stationConfig) {
        $httpPort = isset($_stationConfig['http_port']) && (int) $_stationConfig['http_port'] > 0
                  ? (int) $_stationConfig['http_port'] : 80;
        $channel = isset($_stationConfig['channel']) ? (int) $_stationConfig['channel'] : 1;
        $url = 'http://' . $_stationConfig['ip'] . ':' . $httpPort
             . '/cgi-bin/snapshot.cgi?channel=' . $channel;

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $_stationConfig['username'] . ':' . $_stationConfig['password'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => self::SNAPSHOT_TIMEOUT,
        ));
        $image = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        /*
         * On valide la signature JPEG, pas le code HTTP : le portier répond
         * parfois 200 avec un message texte dans le corps.
         */
        if ($image === false || strlen($image) < 1024 || substr($image, 0, 2) !== "\xFF\xD8") {
            VtoLog::error('capture impossible : ' . $this->snapshotFailure($errno, $error, $code));
            return false;
        }

        $dir = isset($this->config['snapshot_dir']) ? $this->config['snapshot_dir'] : '';
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true))) {
            VtoLog::error('dossier des captures inaccessible : ' . $dir);
            return false;
        }
        $name = 'vto' . (int) $_stationConfig['id'] . '_' . gmdate('Ymd-His')
              . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (@file_put_contents($dir . '/' . $name, $image) === false) {
            VtoLog::error('écriture de la capture impossible : ' . $dir . '/' . $name);
            return false;
        }
        $this->purgeSnapshots($dir, (int) $_stationConfig['id']);
        VtoLog::info('capture enregistrée : ' . $name);
        return $name;
    }

    private function purgeSnapshots($_dir, $_stationId) {
        $keep = max(1, (int) $this->config['snapshot_keep']);
        $files = glob($_dir . '/vto' . $_stationId . '_*.jpg');
        if ($files === false || count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    /*
     * L'erreur curl passe avant le code HTTP : en authentification Digest, un
     * délai dépassé sur la seconde requête laisse CURLINFO_HTTP_CODE à 401,
     * celui du challenge — le lire d'abord ferait accuser le mot de passe.
     */
    private function snapshotFailure($_errno, $_error, $_code) {
        if ($_errno == CURLE_OPERATION_TIMEDOUT) {
            return 'le portier n\'a pas répondu à temps';
        }
        if ($_errno == CURLE_COULDNT_CONNECT) {
            return 'connexion refusée par le portier';
        }
        if ($_errno != 0) {
            return $_error . ' (curl ' . $_errno . ')';
        }
        if ($_code == 401 || $_code == 403) {
            return 'identifiants refusés';
        }
        if ($_code == 400) {
            return 'le portier refuse ce canal';
        }
        return 'réponse inattendue (HTTP ' . $_code . ')';
    }

    /* ------------------------------------------------------- envoi vers Jeedom */

    /*
     * Les envois sont mis en file et confiés à un processus fils : un curl_exec
     * synchrone dans la boucle gèlerait la lecture du portier et ferait expirer
     * sa session.
     */
    private function push($_events) {
        $this->pushQueue[] = $_events;
        if (count($this->pushQueue) > self::PUSH_QUEUE_MAX) {
            array_shift($this->pushQueue);
            VtoLog::warning('file d\'envoi saturée, le plus ancien lot est abandonné');
        }
        $this->drainPushQueue();
    }

    private function drainPushQueue() {
        if ($this->pushPid > 0) {
            /*
             * 0  : le fils tourne encore, on attend.
             * >0 : il vient de se terminer.
             * -1 : il n'existe plus — reapChildren() l'a déjà récolté. Traiter ce
             *      cas comme « terminé » est indispensable : sinon pushPid reste
             *      positionné à jamais et la file n'est plus jamais vidée.
             */
            if (pcntl_waitpid($this->pushPid, $status, WNOHANG) === 0) {
                return;
            }
            $this->pushPid = 0;
        }
        if (empty($this->pushQueue)) {
            return;
        }

        // Coalescence : tout ce qui attend part dans un seul POST.
        $batch = array();
        foreach ($this->pushQueue as $queued) {
            $batch = array_merge($batch, $queued);
        }
        $this->pushQueue = array();

        $pid = pcntl_fork();
        if ($pid == -1) {
            VtoLog::error('fork impossible pour l\'envoi, lot abandonné');
            return;
        }
        if ($pid > 0) {
            $this->pushPid = $pid;
            return;
        }
        $this->closeInheritedSockets();
        $this->callback('', array('events' => $batch));
        exit(0);
    }

    /*
     * Un fils qui garderait le socket d'écoute empêcherait tout redémarrage du
     * démon (« Address already in use ») pendant toute sa durée de vie.
     */
    private function closeInheritedSockets() {
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
        foreach ($this->clients as $client) {
            if (is_resource($client->socket)) {
                fclose($client->socket);
            }
            $client->socket = null;
        }
    }

    private function reapChildren() {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // Rien à faire du code de retour : les fils journalisent eux-mêmes.
        }
    }

    /* Le portier annonce un Pulse sans jamais envoyer le Stop correspondant :
     * c'est le démon qui le produit, sans quoi la commande resterait à 1. */
    private function flushPulseResets() {
        if (empty($this->pulseResets)) {
            return;
        }
        $now = time();
        $batch = array();
        foreach ($this->pulseResets as $key => $deadline) {
            if ($deadline > $now) {
                continue;
            }
            $parts = explode('|', $key, 2);
            if (count($parts) == 2) {
                $batch[] = array(
                    'station_id' => (int) $parts[0],
                    'code'       => $parts[1],
                    'action'     => 'Stop',
                    'index'      => 0,
                    'data'       => array(),
                    /* Jeedom doit pouvoir distinguer cette fin de celles que le
                     * portier envoie vraiment : elle éteint une commande, elle
                     * ne constate rien. */
                    'synthetic'  => true,
                    'time'       => date('Y-m-d H:i:s'),
                );
            }
            unset($this->pulseResets[$key]);
        }
        if (!empty($batch)) {
            $this->push($batch);
        }
    }

    private function ticks() {
        foreach ($this->clients as $client) {
            if ($client->state != 'connected') {
                continue;
            }
            if (!$client->tick()) {
                $this->dropClient($client, 'liaison silencieuse');
            }
        }
    }

    /* ------------------------------------------------------- ordres de Jeedom */

    private function acceptOrders() {
        while (true) {
            $conn = @stream_socket_accept($this->listener, 0);
            if ($conn === false) {
                return;
            }
            $this->handleOrder($conn);
        }
    }

    /*
     * Lecture non bloquante bornée à 1 s : un scan de port ou un client mort ne
     * doit pas immobiliser la boucle, faute de quoi la session du portier expire.
     */
    private function handleOrder($_conn) {
        stream_set_blocking($_conn, false);
        $line = '';
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $read = array($_conn); $write = null; $except = null;
            if (@stream_select($read, $write, $except, 0, 100000) < 1) {
                continue;
            }
            $chunk = @fread($_conn, 65535);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $line .= $chunk;
            if (strpos($line, "\n") !== false || strlen($line) > 65535) {
                break;
            }
        }

        $order = json_decode(trim($line), true);
        if (!is_array($order) || !isset($order['apikey']) || !is_string($order['apikey'])
         || !hash_equals((string) $this->opt['apikey'], $order['apikey'])) {
            VtoLog::warning('ordre local rejeté : clé API invalide');
            @fwrite($_conn, json_encode(array('state' => 'error', 'result' => 'invalid apikey')) . "\n");
            @fclose($_conn);
            return;
        }

        $result = array('state' => 'ok');
        switch (isset($order['order']) ? $order['order'] : '') {
            case 'reload':
                /*
                 * La relecture est différée au tour suivant : l'exécuter ici
                 * ferait une requête HTTP vers Jeedom alors qu'un worker Jeedom
                 * attend déjà notre réponse — interblocage garanti quand PHP-FPM
                 * est saturé.
                 */
                $this->reloadPending = true;
                break;

            case 'reconnect':
                $id = isset($order['id']) ? (int) $order['id'] : 0;
                if (isset($this->clients[$id])) {
                    VtoLog::info($this->clients[$id]->name() . ' reconnexion demandée');
                    $this->clients[$id]->disconnect();
                    /* Une reconnexion demandée à la main repart du transport
                     * préféré : c'est le levier qui sort d'un repli sans attendre
                     * que le transport de secours échoue à son tour. */
                    $this->clients[$id] = $this->makeTransport($this->clients[$id]->config);
                    $this->clients[$id]->scheduleRetry(0, true);
                } else {
                    $result['state'] = 'error';
                    $result['result'] = 'portier inconnu';
                }
                break;

            case 'status':
                $stations = array();
                foreach ($this->clients as $id => $client) {
                    $stations[] = array(
                        'id'        => $id,
                        'name'      => $client->config['name'],
                        'state'     => $client->state,
                        'transport' => $client->label(),
                    );
                }
                $result['result'] = array('stations' => $stations);
                break;

            default:
                $result['state'] = 'error';
                $result['result'] = 'commande inconnue';
        }

        @fwrite($_conn, json_encode($result) . "\n");
        @fclose($_conn);
    }
}

/* ------------------------------------------------------------------ signaux */
$daemon = new VtoDaemon($opt);
$shutdown = function ($signo) use ($daemon) {
    VtoLog::info('signal ' . $signo . ' reçu, arrêt en cours');
    $daemon->stop();
};
pcntl_signal(SIGTERM, $shutdown);
pcntl_signal(SIGINT,  $shutdown);
pcntl_signal(SIGHUP,  $shutdown);

exit($daemon->run());
