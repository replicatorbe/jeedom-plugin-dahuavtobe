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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
 * plugin : les classes annexes doivent être incluses explicitement. */
require_once __DIR__ . '/dahuavtobeCallLog.class.php';
require_once __DIR__ . '/dahuavtobeVision.class.php';

class dahuavtobe extends eqLogic {

    /* Un seul rechargement du démon par requête HTTP, même si trois portiers sont enregistrés. */
    private static $_reloadScheduled = false;

    /* Le coeur a déjà mis l'id à null quand postRemove s'exécute. */
    private $_removedId = 0;

    /*
     * Ce que le portier annonce quand il se passe quelque chose.
     *
     * BackKeyLight est la pièce maîtresse : c'est lui qui porte l'état de
     * l'appel dans son champ State, et c'est le seul code dont on sache, sur ce
     * modèle, qu'il marque le DÉBUT de la sonnerie. Les autres décrivent la
     * suite — décroché, raccroché, abandon — ou concernent la porte.
     *
     * La table est volontairement large : un code de trop ne coûte qu'une ligne
     * ignorée, un code manquant coûte une sonnerie perdue, et le catalogue varie
     * d'un micrologiciel à l'autre. L'onglet Diagnostic montre ce qui arrive
     * réellement ; ce qui n'est pas dans cette table y apparaît quand même.
     *
     * 'cmd'    : logicalId de la commande binaire mise à jour
     * 'value'  : 'action' suit Start/Stop du flux ; 1 ou 0 force la valeur
     * 'state'  : texte repris dans etat_appel au début de l'événement
     * 'state_stop' : texte repris dans etat_appel à sa fin, et seulement si le
     *            portier a réellement envoyé cette fin — jamais sur une fin
     *            fabriquée par le démon, qui ne sait rien de l'appel
     * 'label'  : libellé lisible, repris dans dernier_evenement
     * 'hold'   : l'événement décrit un ÉTAT, pas une impulsion. Sa valeur ne
     *            doit jamais être remise à zéro d'office : une porte ouverte le
     *            reste jusqu'à ce que le portier annonce le contraire. Sans ce
     *            drapeau, le Stop de synthèse du démon refermait la porte cinq
     *            secondes après l'avoir ouverte — constaté, et parfaitement
     *            silencieux.
     */
    private static $_events = array(
        /* --- Appel ------------------------------------------------------- */
        /* State porte tout : le traitement est à part, dans applyBackKeyLight(). */
        'BackKeyLight'   => array('cmd' => null, 'label' => 'Bouton d\'appel'),

        /* Le portier lance son appel SIP vers le moniteur intérieur. C'est le
         * filet de secours si BackKeyLight venait à manquer. */
        'Invite'         => array('cmd' => 'sonnerie', 'value' => 1,
                                  'state' => 'Sonne', 'label' => 'Appel lancé'),
        /* Le Stop est ici un vrai événement du portier, pas une fin de synthèse :
         * il dit que l'appel s'est terminé sans réponse. Sans state_stop,
         * l'état restait sur « Sonne » longtemps après le départ du visiteur. */
        'CallNoAnswered' => array('cmd' => 'sonnerie', 'value' => 'action',
                                  'state' => 'Sonne', 'state_stop' => 'Appel manqué',
                                  'label' => 'Appel sans réponse'),
        /* IgnoreInvite : le moniteur intérieur a DÉCROCHÉ. Le nom prête à
         * confusion — il désigne l'invitation SIP que le portier cesse de
         * relancer — et toutes les implémentations de référence le documentent
         * comme « VTH answered call from VTO ». Le lire comme un refus mettrait
         * l'état exactement à l'envers. */
        'IgnoreInvite'   => array('cmd' => 'sonnerie', 'value' => 0,
                                  'state' => 'En conversation', 'label' => 'Appel décroché'),
        'PassiveHungup'  => array('cmd' => 'sonnerie', 'value' => 0,
                                  'state' => 'Repos', 'label' => 'Raccroché'),
        'CallSnap'       => array('cmd' => null, 'hold' => true, 'label' => 'Image d\'appel'),
        /* Compte rendu que le portier écrit dans son journal à la fin de chaque
         * appel. EndState « Missed » est, sur le VTO2211G, le seul signal
         * direct d'un appel manqué : BackKeyLight n'y arrive jamais. Le
         * traitement est à part, dans applyVideoTalkLog(). Événement ponctuel,
         * sans fin : hold empêche le démon d'en fabriquer une. */
        'VideoTalkLog'   => array('cmd' => null, 'hold' => true, 'label' => 'Fin d\'appel'),

        /* --- Porte ------------------------------------------------------- */
        'AccessControl'  => array('cmd' => null, 'hold' => true, 'label' => 'Ouverture de la porte'),
        'DoorStatus'     => array('cmd' => 'porte', 'hold' => true, 'label' => 'État de la porte'),
        'DoorNotClosed'  => array('cmd' => 'porte_non_fermee', 'value' => 'action',
                                  'label' => 'Porte restée ouverte'),

        /* --- Appareil ---------------------------------------------------- */
        'AlarmLocal'     => array('cmd' => 'sabotage', 'value' => 'action', 'label' => 'Alarme locale'),
        'NetAbort'       => array('cmd' => null, 'hold' => true, 'label' => 'Coupure réseau'),
        'Reboot'         => array('cmd' => null, 'hold' => true, 'label' => 'Redémarrage du portier'),

        /* --- Plugin ------------------------------------------------------ */
        /* Émis par jeeDahuaVto.php lui-même, juste après son avertissement :
         * seulement pour que « Dernier événement » le dise en clair. */
        'SnapshotFailed' => array('cmd' => null, 'hold' => true, 'label' => 'Capture impossible'),
    );

    /*
     * Codes connus et délibérément laissés de côté. Ils restent dans la mémoire
     * de diagnostic, mais ne touchent ni au journal ni à « Dernier événement » :
     * chaque appel en produit une demi-douzaine, qui noyaient les lignes utiles.
     *
     * DGSErrorReport : même ErrorCode à chaque appel, sans conséquence visible.
     * _DoTalkAction_, _CallNoAnswer_, RequestCallState : doublons internes de
     *   ce que disent déjà Invite, CallNoAnswered et VideoTalkLog.
     * DoorControl : doublon d'AccessControl.
     */
    private static $_quiet = array('DGSErrorReport', '_DoTalkAction_', '_CallNoAnswer_',
                                   'RequestCallState', 'DoorControl');

    /*
     * Signification du champ State de BackKeyLight.
     *
     * 1 et 2 valent tous deux « ça sonne » : les implémentations de référence se
     * partagent entre les deux selon le modèle, et rien ne permet de deviner
     * lequel sort sur un appareil donné. Les accepter tous les deux ne coûte rien.
     */
    private static $_backKeyLight = array(
        0  => array('state' => 'Repos',                 'ring' => 0),
        1  => array('state' => 'Sonne',                 'ring' => 1),
        2  => array('state' => 'Sonne',                 'ring' => 1),
        4  => array('state' => 'Message vocal',         'ring' => 0),
        5  => array('state' => 'En conversation',       'ring' => 0),
        6  => array('state' => 'Appel manqué',          'ring' => 0, 'missed' => true),
        7  => array('state' => 'Appel vers le portier', 'ring' => 0),
        8  => array('state' => 'Porte déverrouillée',   'ring' => 0),
        9  => array('state' => 'Déverrouillage refusé', 'ring' => 0),
        11 => array('state' => 'Portier redémarré',     'ring' => 0),
    );

    /*
     * Ce qui déclenche une capture chez le démon. Transmis dans sa configuration
     * plutôt que codé chez lui : c'est une question de modèle et de réglage, pas
     * de plomberie, et cela a sa place ici, à côté de la table des événements.
     *
     * BackKeyLight porte une condition, et elle est indispensable : ce code
     * annonce AUSSI la fin de l'appel (état 5, 6) et le retour au repos (état
     * 0). Sans la condition, le portier photographiait une deuxième fois
     * quelques secondes après le départ du visiteur, et la commande Image
     * finissait par montrer un seuil vide à la place du visage attendu.
     *
     * Les deux réglages sont traduits en liste ici plutôt que transmis tels
     * quels : le démon n'a pas à savoir pourquoi un code déclenche une photo,
     * seulement lesquels le font.
     */
    public static function shotCodes() {
        $codes = array();
        /* 'reason' dit au démon POURQUOI il photographie : seule une sonnerie
         * mérite l'analyse du visiteur. Un habitant qui entre avec son badge
         * n'a rien à faire chez un service d'analyse d'images. */
        if ((int) config::byKey('snapshot_on_ring', __CLASS__, 1) === 1) {
            $codes[] = array('code' => 'BackKeyLight', 'field' => 'State', 'values' => array(1, 2), 'reason' => 'ring');
            $codes[] = array('code' => 'Invite', 'reason' => 'ring');
            $codes[] = array('code' => 'CallNoAnswered', 'reason' => 'ring');
        }
        /* Une ouverture par badge ou par code ne sonne pas : sans cette capture,
         * rien dans Jeedom ne dit qui vient d'entrer. */
        if ((int) config::byKey('snapshot_on_unlock', __CLASS__, 1) === 1) {
            $codes[] = array('code' => 'AccessControl', 'reason' => 'unlock');
        }
        return $codes;
    }

    /*
     * Codes pour lesquels le démon ne doit produire aucune fin d'office. Ils
     * sont déduits de la table : la règle appartient au modèle d'événements, le
     * démon n'a qu'à l'appliquer.
     */
    public static function holdCodes() {
        $codes = array();
        foreach (self::$_events as $code => $map) {
            if (!empty($map['hold'])) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /* ========================================================== DÉMON */

    /*
     * L'état du processus se détermine indépendamment de « launchable » : si
     * l'absence de portier configuré rendait aussi l'état « nok », le coeur
     * croirait le démon arrêté et ne l'arrêterait jamais.
     */
    public static function deamon_info() {
        $return = array('log' => __CLASS__ . 'd', 'state' => 'nok', 'launchable' => 'nok');

        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                /* Un fichier de PID orphelin empêcherait à jamais le chien de garde
                 * de relancer le démon : on le retire dès qu'il ment. */
                @unlink($pid_file);
            }
        }

        $configured = 0;
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip') != '') {
                $configured++;
            }
        }
        if ($configured > 0) {
            $return['launchable'] = 'ok';
        } else {
            $return['launchable_message'] = __('Aucun portier actif n\'est configuré.', __FILE__);
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();

        $info = self::deamon_info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' '
                              . $info['launchable_message']);
        }

        $daemon = realpath(__DIR__ . '/../../resources/dahuavtobed/dahuavtobed.php');
        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder(__CLASS__) . '/deamon.pid');
        $cmd .= ' --socketport ' . escapeshellarg(config::byKey('socketport', __CLASS__, 55061));
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel(__CLASS__)));

        /* La clé API passe par l'entrée standard et jamais par la ligne de
         * commande : ps est lisible par n'importe quel utilisateur local. */
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey(__CLASS__)) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog(__CLASS__ . 'd') . ' 2>&1 &';

        log::add(__CLASS__, 'info', __('Lancement du démon', __FILE__));
        exec($full);

        for ($i = 1; $i <= 30; $i++) {
            $info = self::deamon_info();
            if ($info['state'] == 'ok') {
                message::removeAll(__CLASS__, 'unableStartDeamon');
                return true;
            }
            sleep(1);
        }

        /* log::add ne pose un message que si le réglage global addMessageForErrorLog
         * est actif, et il ne l'est pas par défaut : le message est explicite. */
        log::add(__CLASS__, 'error', __('Le démon n\'a pas démarré. Consultez le journal', __FILE__)
               . ' ' . __CLASS__ . 'd.');
        message::add(__CLASS__, __('Le démon n\'a pas démarré. Consultez le journal', __FILE__)
                   . ' ' . __CLASS__ . 'd.', null, 'unableStartDeamon');
        return false;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid != '') {
                system::kill($pid);
            }
            @unlink($pid_file);
        }
        /* Filets de sécurité : un démon lancé à la main, ou dont le fichier de PID
         * a été perdu, garderait le port d'ordres occupé. */
        system::kill('resources/dahuavtobed/dahuavtobed.php');
        system::fuserk(config::byKey('socketport', __CLASS__, 55061));
        return true;
    }

    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/dahuavtobe/core/php/jeeDahuaVto.php';
    }

    /*
     * Configuration envoyée au démon. Elle est volontairement autosuffisante :
     * le démon ne charge pas core.inc.php, il ne peut donc rien relire du coeur.
     * La timezone en fait partie — un processus CLI daterait ses événements en
     * UTC, et le portier lui-même est à l'heure UTC.
     */
    public static function getDaemonConfig() {
        $stations = array();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip') == '') {
                continue;
            }
            $stations[] = array(
                'id'        => (int) $eqLogic->getId(),
                'name'      => $eqLogic->getHumanName(),
                'ip'        => $eqLogic->getConfiguration('ip'),
                'http_port' => (int) $eqLogic->getConfiguration('http_port', 80),
                'dhip_port' => (int) $eqLogic->getConfiguration('dhip_port', 5000),
                'username'  => $eqLogic->getConfiguration('username', 'admin'),
                'password'  => $eqLogic->getConfiguration('password'),
                'transport' => $eqLogic->getConfiguration('transport', 'auto'),
                'channel'   => (int) $eqLogic->getConfiguration('channel', 1),
                /* Le démon ne fait la rafale de photos et l'analyse que pour les
                 * portiers qui l'ont demandée, et seulement si une clé existe :
                 * sans elle, chaque sonnerie coûterait deux photos pour rien. */
                'ai'        => $eqLogic->visionEnabled(),
            );
        }

        return array(
            'timezone'        => config::byKey('timezone'),
            'stations'        => $stations,
            'heartbeat'       => (int) config::byKey('event_heartbeat', __CLASS__, 10),
            'reconnect_delay' => (int) config::byKey('reconnect_delay', __CLASS__, 15),
            'snapshot_keep'   => (int) config::byKey('snapshot_keep', __CLASS__, 50),
            'snapshot_dir'    => self::snapshotDir(),
            'shot_codes'      => self::shotCodes(),
            'hold_codes'      => self::holdCodes(),
            'pulse_duration'  => (int) config::byKey('pulse_duration', __CLASS__, 5),
            'vision'          => self::visionSettings(),
            'vision_images'   => self::visionImages(),
        );
    }

    /* Envoi d'un ordre au démon par le socket local. */
    public static function sendToDaemon($_payload, $_waitAnswer = false, $_connectTimeout = 2) {
        $info = self::deamon_info();
        if ($info['state'] != 'ok') {
            return false;
        }
        $port   = (int) config::byKey('socketport', __CLASS__, 55061);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, $_connectTimeout);
        if (!$socket) {
            log::add(__CLASS__, 'debug', __('Le démon n\'a pas accepté la connexion :', __FILE__)
                   . ' ' . $errstr);
            return false;
        }
        $_payload['apikey'] = jeedom::getApiKey(__CLASS__);
        fwrite($socket, json_encode($_payload) . "\n");

        $answer = null;
        if ($_waitAnswer) {
            stream_set_timeout($socket, 5);
            $answer = json_decode(trim((string) fgets($socket, 65536)), true);
        }
        fclose($socket);
        return $_waitAnswer ? $answer : true;
    }

    /*
     * Une seule notification par requête HTTP : enregistrer trois portiers d'affilée
     * ne doit pas reconnecter le démon trois fois.
     */
    public static function reloadDaemonConfig() {
        if (self::$_reloadScheduled) {
            return;
        }
        self::$_reloadScheduled = true;
        register_shutdown_function(function () {
            dahuavtobe::sendToDaemon(array('order' => 'reload'));
        });
    }

    /*
     * Le coeur appelle <plugin>::postConfig_<clé>() après avoir enregistré un
     * réglage (config.class.php, config::save). Sans ces méthodes, changer le
     * battement du flux ou la durée de la sonnerie ne produisait rien : le démon
     * gardait la configuration reçue à son démarrage. Le réglage paraissait pris
     * en compte, et il ne l'était pas — c'est le pire des deux mondes.
     */
    public static function postConfig_event_heartbeat($_value)    { self::reloadDaemonConfig(); }
    public static function postConfig_reconnect_delay($_value)    { self::reloadDaemonConfig(); }
    public static function postConfig_pulse_duration($_value)     { self::reloadDaemonConfig(); }
    public static function postConfig_snapshot_on_ring($_value)   { self::reloadDaemonConfig(); }
    public static function postConfig_snapshot_on_unlock($_value) { self::reloadDaemonConfig(); }
    public static function postConfig_snapshot_keep($_value)      { self::reloadDaemonConfig(); }
    public static function postConfig_ai_apikey($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_ai_base_url($_value)        { self::reloadDaemonConfig(); }
    public static function postConfig_ai_model($_value)           { self::reloadDaemonConfig(); }
    public static function postConfig_ai_timeout($_value)         { self::reloadDaemonConfig(); }
    public static function postConfig_ai_images($_value)          { self::reloadDaemonConfig(); }
    public static function postConfig_ai_context($_value)         { self::reloadDaemonConfig(); }

    /*
     * Le port des ordres, lui, ne se recharge pas : le démon l'a ouvert au
     * démarrage et ne peut en changer sans être relancé. Le dire vaut mieux que
     * de laisser croire au changement — ou que de relancer le démon dans la
     * requête qui enregistre le réglage, ce qui figerait la page une demi-minute.
     */
    public static function postConfig_socketport($_value) {
        if (self::deamon_info()['state'] == 'ok') {
            message::add(__CLASS__, __('Le port des ordres a changé : redémarrez le démon pour qu\'il soit pris en compte.', __FILE__),
                         null, 'socketportChanged');
        }
    }

    /* ========================================================== REQUÊTES CGI */

    /* Requête HTTP CGI authentifiée en Digest sur le portier. */
    /* $_logLevel : les appelants réguliers (sonde du cron, rattrapage) passent
     * 'debug'. Un portier débranché, sinon, faisait 1 440 erreurs par jour, et
     * la commande « En ligne » dit déjà ce qu'il en est. */
    public static function cgiRequest($_eqLogic, $_path, $_binary = false, $_timeout = 10, &$_detail = null, $_logLevel = 'error') {
        $port = (int) $_eqLogic->getConfiguration('http_port', 80);
        $url  = 'http://' . $_eqLogic->getConfiguration('ip') . ':' . ($port > 0 ? $port : 80)
              . '/cgi-bin/' . $_path;

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $_eqLogic->getConfiguration('username', 'admin') . ':'
                                    . $_eqLogic->getConfiguration('password'),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $_timeout,
        ));
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        $_detail = array('code' => $code, 'curl' => $error);

        if ($result === false || $code != 200) {
            log::add(__CLASS__, $_logLevel, __('Requête CGI en échec :', __FILE__) . ' ' . $url
                   . ' (HTTP ' . $code . ($error != '' ? ' / ' . $error : '') . ')');
            return false;
        }
        /* Le portier répond 200 avec un corps « Error: ... » sur une action refusée. */
        if (!$_binary && stripos(trim($result), 'Error') === 0) {
            log::add(__CLASS__, 'debug', __('Le portier a refusé la requête :', __FILE__) . ' ' . $_path);
            return false;
        }
        return $result;
    }

    public static function describeCgiFailure($_detail) {
        $code = isset($_detail['code']) ? (int) $_detail['code'] : 0;
        switch ($code) {
            case 0:
                return __('Le portier est injoignable. Vérifiez son adresse, son port HTTP et le réseau.', __FILE__)
                     . (isset($_detail['curl']) && $_detail['curl'] != '' ? ' (' . $_detail['curl'] . ')' : '');
            case 401:
            case 403:
                return __('Le portier a refusé les identifiants.', __FILE__);
            case 400:
                return __('Le portier ne propose pas cette fonction, ou refuse ce canal.', __FILE__);
            case 404:
                return __('Ce portier ne propose pas cette fonction.', __FILE__);
        }
        return __('Le portier a répondu HTTP', __FILE__) . ' ' . $code . '.';
    }

    /* Test de connexion : renvoie un tableau de lignes lisibles pour l'interface. */
    public function testConnection() {
        $lines = array();
        $detail = null;

        $type = self::cgiRequest($this, 'magicBox.cgi?action=getDeviceType', false, 8, $detail);
        if ($type === false) {
            throw new Exception(self::describeCgiFailure($detail));
        }
        $lines['type'] = trim(str_replace('type=', '', trim($type)));

        $version = self::cgiRequest($this, 'magicBox.cgi?action=getSoftwareVersion', false, 8);
        if ($version !== false) {
            $lines['version'] = trim(str_replace('version=', '', trim($version)));
        }
        $serial = self::cgiRequest($this, 'magicBox.cgi?action=getSerialNo', false, 8);
        if ($serial !== false) {
            $lines['serial'] = trim(str_replace('sn=', '', trim($serial)));
        }

        /* L'horloge du portier est souvent en UTC : le signaler évite de chercher
         * longtemps pourquoi les événements arrivent décalés. */
        $time = self::cgiRequest($this, 'global.cgi?action=getCurrentTime', false, 8);
        if ($time !== false) {
            $lines['time'] = trim(str_replace('result=', '', trim($time)));
        }

        $this->setConfiguration('model', $lines['type']);
        if (isset($lines['version'])) {
            $this->setConfiguration('firmware', $lines['version']);
        }
        $this->save(true);

        return $lines;
    }

    /* ========================================================== CAPTURES */

    /*
     * Forme de tout nom de capture : vto<id>_<date>_<jeton>.jpg.
     *
     * Une seule expression pour le passe-plat qui sert l'image et pour le
     * point d'entrée qui reçoit les noms du démon : deux copies finiraient par
     * diverger, et l'une des deux portes laisserait passer ce que l'autre
     * refuse. Le groupe capture l'identifiant du portier, dont le passe-plat a
     * besoin pour vérifier les droits ; les autres l'ignorent.
     *
     * Le modificateur D compte : sans lui, « $ » tolère un saut de ligne final.
     *
     * Le démon compose aussi ces noms (VtoDaemon::fetchSnapshot) mais
     * ne charge pas le coeur : il garde sa propre écriture, qui doit rester
     * conforme à celle-ci.
     */
    const SNAPSHOT_PATTERN = '/^vto(\d+)_\d{8}-\d{6}_[0-9a-f]{8}\.jpg$/D';

    public static function snapshotDir() {
        return __DIR__ . '/../../data/snapshots';
    }

    /*
     * Capture immédiate. Le portier répond parfois 200 avec un message texte :
     * on valide la signature JPEG et non le code HTTP.
     */
    public function takeSnapshot() {
        $channel = (int) $this->getConfiguration('channel', 1);
        $detail  = null;
        $image   = self::cgiRequest($this, 'snapshot.cgi?channel=' . $channel, true, 15, $detail);

        if ($image === false || strlen($image) < 1024 || substr($image, 0, 2) !== "\xFF\xD8") {
            throw new Exception(__('La capture a échoué.', __FILE__) . ' ' . self::describeCgiFailure($detail));
        }

        $dir = self::snapshotDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        /* gmdate des deux côtés, et un jeton aléatoire pour que l'URL de l'image
         * ne soit pas devinable depuis l'horodatage. */
        $name = 'vto' . $this->getId() . '_' . gmdate('Ymd-His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($dir . '/' . $name, $image);

        $this->purgeSnapshots();
        $this->checkAndUpdateCmd('snapshot', self::snapshotUrl($name));
        $this->checkAndUpdateCmd('snapshot_file', self::snapshotPath($name));
        return $name;
    }

    public static function snapshotUrl($_name) {
        return 'plugins/dahuavtobe/core/php/snapshot.php?file=' . rawurlencode($_name);
    }

    /*
     * Chemin absolu de l'image sur le disque, pour les notifications.
     *
     * L'URL de snapshotUrl() exige une session Jeedom : Telegram, un serveur de
     * mail ou un téléphone ne l'ouvriront jamais. Un plugin de notification,
     * lui, tourne sur la même machine et sait joindre un fichier local.
     *
     * realpath retire le « .. » de snapshotDir() : certains plugins comparent
     * le chemin reçu à une racine autorisée, ou l'affichent tel quel dans leur
     * journal. Il échoue si le dossier n'existe pas encore ; le chemin brut
     * reste alors valable, simplement moins lisible.
     */
    public static function snapshotPath($_name) {
        $dir = realpath(self::snapshotDir());
        return ($dir !== false ? $dir : self::snapshotDir()) . '/' . $_name;
    }

    /*
     * Widget de la commande Image (« dahuavtobe::dahuavtobe »).
     *
     * Ses textes passent par ici plutôt que par des {{…}} dans le modèle : le
     * cœur traduit un modèle de plugin sous le nom core/template/…, sans le
     * préfixe plugins/dahuavtobe/, et ne trouve donc jamais nos traductions.
     * Le cœur change les guillemets doubles des valeurs en simples : le modèle
     * les place entre guillemets doubles, où une apostrophe ne gêne pas.
     */
    public static function templateWidget() {
        return array('info' => array('string' => array('dahuavtobe' => array(
            'template' => 'dahuavtobe',
            'replace'  => array(
                '#vto_no_image#'  => __('Aucune image pour le moment.', __FILE__),
                '#vto_not_found#' => __('Image introuvable — elle a probablement été effacée par la rotation.', __FILE__),
                '#vto_open#'      => __('Ouvrir l\'image en grand', __FILE__),
            ),
        ))));
    }

    /* Rotation : on garde les N dernières images de ce portier. */
    public function purgeSnapshots() {
        /* Au moins une : à 0, la photo qu'on vient de prendre partait avec les
         * autres, et la commande Image pointait sur un fichier effacé. */
        $keep  = max(1, (int) config::byKey('snapshot_keep', __CLASS__, 50));
        $files = glob(self::snapshotDir() . '/vto' . $this->getId() . '_*.jpg');
        if ($files === false || count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    /* ========================================================== VISITEURS */

    /*
     * Réglages du service d'analyse, sous la forme qu'attend dahuavtobeVision.
     * Une seule source pour le démon et pour l'analyse à la main : deux copies
     * finiraient par ne plus parler au même modèle.
     */
    public static function visionSettings() {
        return array(
            'base_url' => trim((string) config::byKey('ai_base_url', __CLASS__, dahuavtobeVision::BASE_URL_DEFAUT)),
            'apikey'   => trim((string) config::byKey('ai_apikey', __CLASS__, '')),
            'model'    => trim((string) config::byKey('ai_model', __CLASS__, dahuavtobeVision::MODELE_DEFAUT)),
            'timeout'  => dahuavtobeVision::delai(config::byKey('ai_timeout', __CLASS__, dahuavtobeVision::TIMEOUT_DEFAUT)),
            'context'  => (string) config::byKey('ai_context', __CLASS__, ''),
            'language' => (string) config::byKey('language', 'core', 'fr_FR'),
        );
    }

    /* Nombre de photos par visite, de 1 à 4. Au-delà, le visiteur a eu le temps
     * de partir, et la réponse arrive d'autant plus tard. */
    public static function visionImages() {
        return max(1, min(4, (int) config::byKey('ai_images', __CLASS__, 3)));
    }

    public static function visionThreshold() {
        return max(0, min(100, (int) config::byKey('ai_threshold', __CLASS__, 70)));
    }

    /* Analyse active pour ce portier : cochée sur l'équipement ET une clé
     * renseignée. Sans clé, chaque sonnerie finirait en « indéterminé » et
     * déclencherait les actions de cette catégorie pour une panne de réglage. */
    public function visionEnabled() {
        return (int) $this->getConfiguration('ai_enable', 0) === 1
            && trim((string) config::byKey('ai_apikey', __CLASS__, '')) !== '';
    }

    /* Libellés lisibles, pour les notifications (#libelle#) et la page. La
     * commande, elle, garde la clé : c'est elle qu'un scénario compare. */
    public static function visionLabels() {
        return array(
            'livreur'       => __('Livreur', __FILE__),
            'demarcheur'    => __('Démarcheur', __FILE__),
            'professionnel' => __('Professionnel', __FILE__),
            'visiteur'      => __('Visiteur', __FILE__),
            'vide'          => __('Personne en vue', __FILE__),
            'indetermine'   => __('Indéterminé', __FILE__),
        );
    }

    /*
     * Applique le résultat d'une analyse, qu'il vienne du démon après une
     * sonnerie ou d'une analyse demandée à la main.
     *
     * $_event : ring_at (horodatage Unix de la sonnerie), images (noms de
     * fichiers), et le résultat de dahuavtobeVision::analyse().
     *
     * Deux gardes, qui tiennent au même repère ring_at :
     *  - une réponse PLUS ANCIENNE que la dernière appliquée est jetée. Si
     *    l'analyse d'un premier visiteur traîne et qu'un second sonne, la
     *    réponse tardive ne doit pas décrire le second ;
     *  - une réponse DÉJÀ appliquée est jetée. Le démon renvoie un lot quand
     *    Jeedom tarde à répondre : sans ce contrôle, la même visite
     *    notifierait deux fois.
     */
    public function applyAnalysis($_event) {
        $ringAt = isset($_event['ring_at']) ? (int) $_event['ring_at'] : time();
        if ($ringAt <= (int) $this->getCache('ai_done_at', 0)) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' '
                   . __('analyse ignorée : une visite plus récente a déjà été décrite.', __FILE__));
            return false;
        }
        $this->setCache('ai_done_at', $ringAt);

        $categories = dahuavtobeVision::CATEGORIES;
        $ok = !empty($_event['ok']);
        $raw = (isset($_event['categorie']) && in_array($_event['categorie'], $categories, true))
             ? $_event['categorie'] : 'indetermine';
        $confidence = isset($_event['confiance']) ? max(0, min(100, (int) $_event['confiance'])) : 0;
        $category = $ok ? $raw : 'indetermine';
        /* Sous le seuil, le plugin ne tranche pas : une notification « livreur »
         * pour un démarcheur est pire que « indéterminé ». La réponse brute
         * reste dans #categorie_brute#, pour qui veut la voir. */
        if ($ok && $category !== 'indetermine' && $confidence < self::visionThreshold()) {
            $category = 'indetermine';
        }

        $error = isset($_event['erreur']) ? (string) $_event['erreur'] : '';
        $description = isset($_event['description']) ? trim((string) $_event['description']) : '';
        if (!$ok) {
            $description = __('Analyse impossible :', __FILE__) . ' ' . $error;
        }
        $indices = (isset($_event['indices']) && is_array($_event['indices'])) ? $_event['indices'] : array();

        /* Seuls des noms de captures du plugin sont acceptés : ils composent un
         * chemin sur le disque, qui part dans une notification. */
        $images = array();
        foreach ((isset($_event['images']) && is_array($_event['images'])) ? $_event['images'] : array() as $name) {
            if (is_string($name) && preg_match(self::SNAPSHOT_PATTERN, $name) === 1) {
                $images[] = $name;
            }
        }
        $best = isset($_event['meilleure']) ? (int) $_event['meilleure'] : 0;
        $image = '';
        if (!empty($images)) {
            $image = self::snapshotPath(isset($images[$best]) ? $images[$best] : $images[0]);
        }
        $date = date('Y-m-d H:i:s', $ringAt);

        $labels = self::visionLabels();
        log::add(__CLASS__, $ok ? 'info' : 'warning', $this->getHumanName() . ' '
               . __('visiteur :', __FILE__) . ' ' . $category
               . ($ok ? ' (' . $raw . ', ' . $confidence . ' %) ' . $description : ' — ' . $error)
               . (isset($_event['duree_ms']) ? ' [' . (int) $_event['duree_ms'] . ' ms]' : ''));

        /* La catégorie en DERNIER : c'est elle qui déclenche les scénarios, et
         * ils doivent trouver la description et l'image déjà à jour. */
        $this->checkAndUpdateCmd('visiteur_confiance', $ok ? $confidence : 0);
        $this->checkAndUpdateCmd('visiteur_description', $description);
        $this->checkAndUpdateCmd('visiteur_image', $image);
        $this->checkAndUpdateCmd('visiteur_date', $date);
        $this->checkAndUpdateCmd('visiteur_categorie', $category);

        $this->scheduleVisitorActions($category, array(
            '#portier#'         => $this->getHumanName(),
            '#categorie#'       => $category,
            '#libelle#'         => $labels[$category],
            '#categorie_brute#' => $raw,
            '#confiance#'       => $ok ? $confidence : 0,
            '#description#'     => $description,
            '#indices#'         => implode(', ', $indices),
            '#image#'           => $image,
            '#date#'            => $date,
            '#erreur#'          => $error,
        ));
        return true;
    }

    /*
     * Analyse de la dernière photo, demandée depuis Jeedom : bouton de la page
     * ou commande « Analyser la dernière photo ». Elle suit exactement le
     * chemin d'une sonnerie — commandes et actions comprises — et c'est ce qui
     * permet de tester une notification sans aller sonner à sa propre porte.
     */
    public function analyseNow() {
        $settings = self::visionSettings();
        if ($settings['apikey'] === '') {
            throw new Exception(__('Aucune clé API n\'est renseignée dans la configuration du plugin.', __FILE__));
        }
        $cmd = $this->getCmd('info', 'snapshot_file');
        $path = is_object($cmd) ? (string) $cmd->execCmd() : '';
        $name = basename($path);
        if ($path === '' || preg_match(self::SNAPSHOT_PATTERN, $name) !== 1 || !is_file(self::snapshotDir() . '/' . $name)) {
            throw new Exception(__('Aucune photo à analyser : prenez-en une d\'abord.', __FILE__));
        }
        $result = dahuavtobeVision::analyse(array(self::snapshotDir() . '/' . $name), $settings);
        $result['images'] = array($name);
        /* L'horloge de Jeedom, à la seconde : une analyse manuelle compte comme
         * une visite nouvelle. Deux clics dans la même seconde n'en font qu'une. */
        $result['ring_at'] = max(time(), (int) $this->getCache('ai_done_at', 0) + 1);
        $this->applyAnalysis($result);
        $labels = self::visionLabels();
        $result['libelle'] = $labels[$result['categorie']];
        $result['seuil'] = self::visionThreshold();
        return $result;
    }

    /*
     * Les actions de la catégorie partent dans un processus PHP détaché, et
     * jamais dans la requête qui reçoit l'analyse.
     *
     * Cette requête est celle du démon, qui abandonne au bout de quatre
     * secondes et renvoie alors le lot. Une notification qui met cinq secondes
     * à partir aurait donc été envoyée deux fois — et une action « wait »
     * aurait gelé la réception des sonneries pendant toute sa durée.
     */
    private function scheduleVisitorActions($_category, $_tags) {
        $all = $this->getConfiguration('ai_actions', array());
        if (!is_array($all) || empty($all[$_category]) || !is_array($all[$_category])) {
            return;
        }
        $key = __CLASS__ . '::actions::' . $this->getId() . '::' . bin2hex(random_bytes(8));
        cache::set($key, json_encode(array('id' => (int) $this->getId(), 'category' => $_category, 'tags' => $_tags)), 120);
        system::php(escapeshellarg(realpath(__DIR__ . '/../php/jeeDahuaVtoActions.php'))
                  . ' key=' . escapeshellarg($key) . ' >> /dev/null 2>&1 &');
    }

    /*
     * Exécute les actions d'une catégorie. Appelée par jeeDahuaVtoActions.php,
     * dans le processus détaché.
     *
     * scenarioExpression::createAndExec est le primitif du coeur pour les
     * actions configurables : commande, scénario, variable ou message, avec
     * les options « désactivée » et « en parallèle » du sélecteur.
     */
    public function runVisitorActions($_category, $_tags) {
        $all = $this->getConfiguration('ai_actions', array());
        if (!is_array($all) || empty($all[$_category]) || !is_array($all[$_category])) {
            return;
        }
        foreach ($all[$_category] as $action) {
            $expression = isset($action['cmd']) ? trim((string) $action['cmd']) : '';
            if ($expression === '') {
                continue;
            }
            $refusal = $this->refuseAction($expression);
            if ($refusal !== '') {
                log::add(__CLASS__, 'warning', $this->getHumanName() . ' ' . __('action ignorée :', __FILE__)
                       . ' ' . $expression . ' — ' . $refusal);
                continue;
            }
            $options = (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array();
            foreach ($options as $key => $value) {
                if (is_string($value)) {
                    $options[$key] = str_replace(array_keys($_tags), array_values($_tags), $value);
                }
            }
            /* Transmis tels quels à un scénario appelé, qui les lit en tags. */
            if (!isset($options['tags']) || $options['tags'] === '') {
                $options['tags'] = $_tags;
            }
            $options['source'] = $this->getHumanName();
            try {
                scenarioExpression::createAndExec('action', $expression, $options);
            } catch (Throwable $e) {
                /* Une action en échec ne doit pas retenir les suivantes. */
                log::add(__CLASS__, 'error', $this->getHumanName() . ' ' . __('action en échec :', __FILE__)
                       . ' ' . $expression . ' — ' . $e->getMessage());
            }
        }
    }

    /*
     * Ce qu'une analyse d'image ne déclenchera jamais : une commande de ce
     * portier — « Ouvrir la porte » la première, et « Analyser » qui
     * bouclerait —, ni une commande d'ouverture de serrure, où qu'elle soit.
     * Le modèle se trompe, et un démarcheur peut tenir devant l'objectif une
     * pancarte écrite pour le tromper : sa réponse informe, elle n'ouvre rien.
     */
    private function refuseAction($_expression) {
        $id = str_replace('#', '', cmd::humanReadableToCmd($_expression));
        if (!is_numeric($id)) {
            return '';
        }
        $cmd = cmd::byId($id);
        if (!is_object($cmd)) {
            return '';
        }
        if ($cmd->getEqLogic_id() == $this->getId()) {
            return __('elle vise le portier lui-même.', __FILE__);
        }
        if (in_array($cmd->getGeneric_type(), array('LOCK_OPEN', 'GB_OPEN', 'GB_TOGGLE'), true)) {
            return __('une analyse d\'image n\'ouvre jamais une serrure ni un portail.', __FILE__);
        }
        return '';
    }

    /* ========================================================== GÂCHE */

    /*
     * Ouverture de la gâche. Trois verrous, parce qu'une commande Jeedom
     * s'exécute aussi bien depuis un scénario que depuis un clic involontaire :
     * le réglage doit être armé dans la configuration du plugin, la commande
     * est créée invisible, et elle demande confirmation au clic. Seul le
     * premier retient un scénario : c'est celui qui est contrôlé ici.
     */
    public function openDoor() {
        if ((int) config::byKey('allow_open_door', __CLASS__, 0) !== 1) {
            throw new Exception(__('L\'ouverture de la porte est désactivée dans la configuration du plugin.', __FILE__));
        }
        if ($this->getConfiguration('ip') == '') {
            throw new Exception(__('Aucune adresse n\'est renseignée pour ce portier.', __FILE__));
        }
        $channel = (int) $this->getConfiguration('channel', 1);
        $userId  = config::byKey('open_door_userid', __CLASS__, 101);
        $detail  = null;

        $result = self::cgiRequest($this, 'accessControl.cgi?action=openDoor&channel=' . $channel
                                 . '&UserID=' . rawurlencode($userId) . '&Type=Remote', false, 10, $detail);
        if ($result === false) {
            throw new Exception(__('L\'ouverture de la porte a échoué.', __FILE__) . ' '
                              . self::describeCgiFailure($detail));
        }
        log::add(__CLASS__, 'info', __('Ouverture de la porte demandée sur', __FILE__)
               . ' ' . $this->getHumanName());
        return true;
    }

    /* ========================================================== CYCLE DE VIE */

    /*
     * preSave ne lève jamais d'exception à la création : le coeur crée
     * l'équipement avec son seul nom, et une validation stricte rendrait le
     * bouton « Ajouter » définitivement inopérant.
     */
    public function preSave() {
        foreach (array('http_port' => 80, 'dhip_port' => 5000,
                       'username' => 'admin', 'transport' => 'auto', 'channel' => 1) as $key => $default) {
            if ($this->getConfiguration($key) === '' || $this->getConfiguration($key) === null) {
                $this->setConfiguration($key, $default);
            }
        }
        if ($this->getId() == '') {
            return;
        }
        if ($this->getConfiguration('ip') != '' && filter_var($this->getConfiguration('ip'), FILTER_VALIDATE_IP) === false
            && !preg_match('/^[a-z0-9.\-]+$/i', $this->getConfiguration('ip'))) {
            throw new Exception(__('L\'adresse du portier n\'est pas une adresse IP ni un nom d\'hôte valide.', __FILE__));
        }
    }

    public function postSave() {
        $this->setLogicalId('vto::' . $this->getId());
        /* setLogicalId après save() demande une écriture directe : passer par
         * save() ici relancerait postSave en boucle. */
        DB::save($this, true);

        $this->createCommands();
        self::reloadDaemonConfig();
    }

    public function preRemove() {
        $this->_removedId = (int) $this->getId();
        return true;
    }

    public function postRemove() {
        if ($this->_removedId > 0) {
            foreach (glob(self::snapshotDir() . '/vto' . $this->_removedId . '_*.jpg') as $file) {
                @unlink($file);
            }
        }
        self::reloadDaemonConfig();
        return true;
    }

    /* ========================================================== COMMANDES */

    /*
     * Création idempotente : une commande existante n'est jamais réécrite.
     * Son nom, sa visibilité et son historisation appartiennent à l'utilisateur
     * dès qu'il y a touché.
     */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = cmd::byEqLogicIdAndLogicalId($this->getId(), $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }

        $name = $_name;
        /* L'unicité SQL porte sur (eqLogic_id, name) : une collision ferait
         * échouer tout l'enregistrement, pas seulement cette commande. */
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }

        $cmd = new dahuavtobeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['generic_type'])) {
            $cmd->setGeneric_type($_options['generic_type']);
        }
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        if (isset($_options['unite'])) {
            $cmd->setUnite($_options['unite']);
        }
        if (isset($_options['configuration'])) {
            foreach ($_options['configuration'] as $key => $value) {
                $cmd->setConfiguration($key, $value);
            }
        }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    /*
     * Peu de commandes visibles : sonnerie, état de l'appel, porte, image,
     * visiteur, en ligne. C'est ce qu'on regarde sur un dashboard.
     *
     * Les autres existent, sont alimentées et restent disponibles pour les
     * scénarios — elles sont simplement masquées. Vingt tuiles empilées pour une
     * sonnette rendraient le dashboard illisible, et l'utilisateur n'a qu'une
     * case à cocher pour en rendre une visible s'il la veut.
     */
    public function createCommands() {
        $order = 0;

        $this->addCmdIfMissing('sonnerie', __('Sonnerie', __FILE__), 'info', 'binary',
            array('isHistorized' => 1, 'order' => $order++));
        $this->addCmdIfMissing('etat_appel', __('État de l\'appel', __FILE__), 'info', 'string',
            array('order' => $order++));
        $this->addCmdIfMissing('dernier_appel', __('Dernier appel', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('appel_manque', __('Appel manqué', __FILE__), 'info', 'binary',
            array('isVisible' => 0, 'isHistorized' => 1, 'order' => $order++));
        $this->addCmdIfMissing('appels_manques_24h', __('Appels manqués (24 h)', __FILE__), 'info', 'numeric',
            array('isHistorized' => 1, 'order' => $order++));

        $this->addCmdIfMissing('porte', __('Porte', __FILE__), 'info', 'binary',
            array('generic_type' => 'LOCK_STATE', 'isHistorized' => 1, 'order' => $order++));
        $this->addCmdIfMissing('dernier_acces', __('Dernier accès', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('porte_non_fermee', __('Porte restée ouverte', __FILE__), 'info', 'binary',
            array('isVisible' => 0, 'order' => $order++));

        /* Le nom du gabarit est qualifié par l'identifiant du plugin. Sans ce
         * préfixe, setTemplate() lui colle « core:: » et le coeur retombe
         * silencieusement sur l'affichage par défaut — une adresse en texte. */
        $this->addCmdIfMissing('snapshot', __('Image', __FILE__), 'info', 'string',
            array('generic_type' => 'CAMERA_URL', 'template' => __CLASS__ . '::' . __CLASS__,
                  'order' => $order++));
        /* Double de la précédente, pour les scénarios : un chemin de fichier
         * que les plugins de notification savent joindre, là où l'URL protégée
         * par session ne sert qu'au dashboard. Masquée, car illisible sur une
         * tuile. */
        $this->addCmdIfMissing('snapshot_file', __('Fichier image', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('en_ligne', __('En ligne', __FILE__), 'info', 'binary',
            array('generic_type' => 'ONLINE', 'order' => $order++));
        $this->addCmdIfMissing('sabotage', __('Alarme locale', __FILE__), 'info', 'binary',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('dernier_evenement', __('Dernier événement', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('dernier_evenement_date', __('Date du dernier événement', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));

        /* Le visiteur, d'après l'analyse de ses photos. La catégorie est la
         * seule visible : c'est elle qu'on lit d'un coup d'oeil, et elle sert
         * de déclencheur. Elle et la date se répètent à l'identique — deux
         * livreurs d'affilée donnent deux fois « livreur », et le second doit
         * déclencher lui aussi. */
        $this->addCmdIfMissing('visiteur_categorie', __('Visiteur', __FILE__), 'info', 'string',
            array('isHistorized' => 1, 'order' => $order++,
                  'configuration' => array('repeatEventManagement' => 'always')));
        $this->addCmdIfMissing('visiteur_description', __('Visiteur - description', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('visiteur_confiance', __('Visiteur - confiance', __FILE__), 'info', 'numeric',
            array('isVisible' => 0, 'order' => $order++, 'unite' => '%'));
        $this->addCmdIfMissing('visiteur_image', __('Visiteur - fichier image', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('visiteur_date', __('Visiteur - date', __FILE__), 'info', 'string',
            array('isVisible' => 0, 'order' => $order++,
                  'configuration' => array('repeatEventManagement' => 'always')));

        $this->addCmdIfMissing('capture', __('Prendre une photo', __FILE__), 'action', 'other',
            array('order' => $order++));
        $this->addCmdIfMissing('analyser', __('Analyser la dernière photo', __FILE__), 'action', 'other',
            array('isVisible' => 0, 'order' => $order++));
        $this->addCmdIfMissing('reconnecter', __('Reconnecter', __FILE__), 'action', 'other',
            array('isVisible' => 0, 'order' => $order++));
        /* Créée invisible : l'ouverture d'une porte d'entrée ne doit pas être à
         * un clic de distance tant que l'utilisateur ne l'a pas voulu.
         * actionConfirm vaut 1 ou rien : le coeur ne compare qu'à 1. Il ne joue
         * que pour les exécutions venues de l'interface (widget, application
         * via JSON-RPC), qui reçoivent un refus -32006 puis rejouent l'appel
         * après la boîte « Êtes-vous sûr » ; un scénario ou l'API HTTP appellent
         * execCmd() directement et ouvrent sans rien demander. */
        $this->addCmdIfMissing('ouvrir', __('Ouvrir la porte', __FILE__), 'action', 'other',
            array('generic_type' => 'LOCK_OPEN', 'isVisible' => 0, 'order' => $order++,
                  'configuration' => array('actionConfirm' => 1)));
    }

    /* ========================================================== ÉVÉNEMENTS */

    /*
     * Codes qui décrivent le déroulement d'un appel. Leur fin fabriquée par le
     * démon sert de garde-fou : si rien n'a décrit la suite entre-temps, l'appel
     * est terminé.
     */
    const CALL_CODES = array('BackKeyLight', 'Invite', 'CallNoAnswered');

    /*
     * L'état de l'appel est écrit deux fois : traduit dans la commande, pour
     * l'utilisateur, et brut dans le cache, pour le plugin.
     *
     * Comparer la commande à elle-même reviendrait à comparer des chaînes
     * traduites : une installation qui passe à l'anglais garderait « Sonne » en
     * base, la comparaison échouerait, et l'état resterait bloqué sans que rien
     * ne l'explique.
     */
    private function setCallState($_key) {
        $this->setCache('call_state', $_key);
        $this->checkAndUpdateCmd('etat_appel', __($_key, __FILE__));
    }

    private function callState() {
        return $this->getCache('call_state', 'Repos');
    }

    /*
     * Applique un événement reçu du démon.
     *
     * Rien n'est jamais rejeté en silence : un code inconnu ne met à jour aucune
     * commande, mais il est journalisé et conservé dans la mémoire de
     * diagnostic. C'est ce qui permettra d'ajouter le code d'un autre modèle
     * sans avoir à instrumenter le démon.
     */
    public function applyEvent($_event) {
        $code   = isset($_event['code']) ? (string) $_event['code'] : '';
        $action = isset($_event['action']) ? (string) $_event['action'] : 'Pulse';
        $data   = isset($_event['data']) && is_array($_event['data']) ? $_event['data'] : array();
        $date   = isset($_event['time']) ? (string) $_event['time'] : date('Y-m-d H:i:s');
        /* Fin fabriquée par le démon faute d'en recevoir une. Elle suffit à
         * éteindre une commande, mais elle ne constate rien : on ne lui laisse
         * pas décrire l'état de l'appel. */
        $synthetic = !empty($_event['synthetic']);

        /* Fin fabriquée d'un code que le plugin ne traite pas : elle n'éteint
         * rien, et le démon l'a inventée. La journaliser doublait chaque ligne
         * « code non traité », et l'afficher faisait parler le portier à sa place. */
        if ($synthetic && !isset(self::$_events[$code])) {
            return;
        }

        $this->pushRaw($_event);

        /*
         * Fin fabriquée d'un événement d'appel, et personne n'a décrit la suite :
         * ni décroché, ni appel manqué, ni raccrochage. L'appel est simplement
         * fini. Sans ce retour au repos, l'état restait sur « Sonne » jusqu'à la
         * visite suivante — c'est ce qu'on voyait sur le dashboard.
         */
        if ($synthetic && $action == 'Stop' && in_array($code, self::CALL_CODES, true)
            && $this->callState() === 'Sonne') {
            $this->setCallState('Repos');
        }

        if (in_array($code, self::$_quiet, true)) {
            return;
        }
        if (!isset(self::$_events[$code])) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' ' . __('code non traité :', __FILE__)
                   . ' ' . $code . ' / ' . $action);
            $this->checkAndUpdateCmd('dernier_evenement', $code . ' ' . $action);
            $this->checkAndUpdateCmd('dernier_evenement_date', $date);
            return;
        }
        $map = self::$_events[$code];

        /* BackKeyLight porte tout son sens dans son champ State : il a son
         * propre traitement, et il est le seul. */
        if ($code == 'BackKeyLight') {
            $this->applyBackKeyLight($data, $action, $date);
        } elseif ($code == 'VideoTalkLog') {
            $this->applyVideoTalkLog($data, $action, $date);
        } elseif (!empty($map['hold']) && $action == 'Stop') {
            /* Fin d'un événement d'état : il n'y a rien à remettre à zéro, et
             * le faire mentirait sur l'état réel de l'appareil. */
            return;
        } elseif ($map['cmd'] !== null) {
            $value = isset($map['value']) ? $map['value'] : 'action';
            if ($value === 'action') {
                $value = ($action == 'Stop') ? 0 : 1;
            } elseif ($action == 'Stop') {
                /* Une valeur forcée décrit le début de l'événement : sa fin doit
                 * rendre la commande à zéro, sinon un appel refusé laisserait la
                 * sonnerie allumée jusqu'au suivant. */
                $value = 0;
            }
            $this->checkAndUpdateCmd($map['cmd'], $value);
        }

        /* DoorStatus annonce l'état réel de la gâche, indépendamment de Start/Stop. */
        if ($code == 'DoorStatus' && isset($data['Status'])) {
            $this->checkAndUpdateCmd('porte', ($data['Status'] == 'Open') ? 1 : 0);
        }

        /* Qui vient d'ouvrir, et par quel moyen. Method est un entier dont seuls
         * quelques cas sont documentés ; l'afficher tel quel vaut mieux que de
         * le taire. */
        if ($code == 'AccessControl' && $action != 'Stop') {
            $this->checkAndUpdateCmd('dernier_acces', $date . ' — ' . self::describeAccess($data));
        }

        if ($action != 'Stop') {
            if (isset($map['state'])) {
                $this->setCallState($map['state']);
            }
        } elseif (isset($map['state_stop']) && !$synthetic) {
            $this->setCallState($map['state_stop']);
        }
        if (in_array($code, self::CALL_CODES, true) && $action != 'Stop') {
            $this->checkAndUpdateCmd('dernier_appel', $date);
        }
        /* Nouvelle sonnerie : l'appel manqué précédent n'est plus d'actualité.
         * BackKeyLight le fait de son côté ; sur un modèle qui ne l'envoie
         * pas, sans ceci, la commande resterait à 1 pour toujours. */
        if (($code == 'Invite' || $code == 'CallNoAnswered') && $action != 'Stop' && !$synthetic) {
            $this->checkAndUpdateCmd('appel_manque', 0);
            $this->rememberLiveRing($date);
        }

        /* Rien de fabriqué ici : cette commande rapporte ce que le portier a
         * annoncé, et une fin inventée par le démon n'en fait pas partie. */
        if (!$synthetic) {
            $label = isset($map['label']) ? __($map['label'], __FILE__) : $code;
            $this->checkAndUpdateCmd('dernier_evenement', $label . ' (' . $action . ')');
            $this->checkAndUpdateCmd('dernier_evenement_date', $date);
        }
    }

    private function applyBackKeyLight($_data, $_action, $_date) {
        /*
         * Fin d'impulsion : le portier n'envoie pas de State, il n'y a donc rien
         * à lire. C'est pourtant le moment le plus important — c'est lui qui
         * éteint la sonnerie.
         *
         * Sans cette remise à zéro, la commande resterait à 1 jusqu'au prochain
         * BackKeyLight : si l'appareil n'annonce pas son retour au repos, elle y
         * resterait indéfiniment, et le déclencheur d'un scénario ne repartirait
         * plus jamais. Une sonnette est une impulsion, pas un état.
         */
        if ($_action == 'Stop') {
            $this->checkAndUpdateCmd('sonnerie', 0);
            return;
        }
        if (!isset($_data['State'])) {
            return;
        }
        $state = (int) $_data['State'];
        if (!isset(self::$_backKeyLight[$state])) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' '
                   . __('état de bouton inconnu :', __FILE__) . ' ' . $state);
            return;
        }
        $known = self::$_backKeyLight[$state];

        $this->checkAndUpdateCmd('sonnerie', $known['ring']);
        $this->setCallState($known['state']);
        if ($known['ring'] == 1) {
            $this->checkAndUpdateCmd('dernier_appel', $_date);
            $this->checkAndUpdateCmd('appel_manque', 0);
            $this->rememberLiveRing($_date);
        }
        if (!empty($known['missed'])) {
            $this->checkAndUpdateCmd('appel_manque', 1);
            $this->countLiveMissedCall($_date);
        }
    }

    /*
     * Fin d'appel écrite au journal du portier. Seul EndState « Missed »
     * change quelque chose : un appel décroché a déjà été décrit par
     * IgnoreInvite. Sur un modèle qui envoie aussi BackKeyLight à l'état 6,
     * l'anti-rebond de countLiveMissedCall() évite de compter l'appel deux fois.
     */
    private function applyVideoTalkLog($_data, $_action, $_date) {
        if ($_action == 'Stop' || !isset($_data['EndState']) || $_data['EndState'] != 'Missed') {
            return;
        }
        $this->checkAndUpdateCmd('sonnerie', 0);
        $this->setCallState('Appel manqué');
        $this->checkAndUpdateCmd('appel_manque', 1);
        $this->countLiveMissedCall($_date);
    }

    /*
     * Sonneries vues en direct : le rattrapage les écarte pour ne pas annoncer
     * comme « rattrapée » une sonnerie déjà signalée. Gardées au moins jusqu'à
     * la relecture suivante du journal, quel que soit son intervalle.
     * Invite et CallNoAnswered arrivent dans la même seconde : une seule entrée.
     */
    private function rememberLiveRing($_date) {
        $time = strtotime($_date);
        if ($time === false) {
            return;
        }
        $keep = max(3600, (int) config::byKey('call_history_interval', __CLASS__, 15) * 60 + 600);
        $rings = array();
        foreach ((array) $this->getCache('live_rings', array()) as $ring) {
            if ((int) $ring > $time - $keep && abs((int) $ring - $time) > dahuavtobeCallLog::LIVE_MATCH) {
                $rings[] = (int) $ring;
            }
        }
        $rings[] = $time;
        $this->setCache('live_rings', $rings);
    }

    /* Traduit le contenu d'un événement d'accès en une ligne lisible. */
    private static function describeAccess($_data) {
        $methods = array(
            1 => 'badge',
            2 => 'mot de passe',
            4 => 'à distance',
            6 => 'empreinte',
        );
        $method = isset($_data['Method']) ? (int) $_data['Method'] : 0;
        $who = isset($_data['UserID']) && $_data['UserID'] !== '' ? (string) $_data['UserID'] : '';
        $text = isset($methods[$method]) ? __($methods[$method], __FILE__)
                                         : __('méthode', __FILE__) . ' ' . $method;
        if ($who !== '') {
            $text .= ' (' . $who . ')';
        }
        if (isset($_data['Status']) && (int) $_data['Status'] === 0) {
            $text .= ' — ' . __('refusé', __FILE__);
        }
        return $text;
    }

    /*
     * Mémoire de diagnostic : les cinquante derniers événements bruts.
     *
     * En cache et non en base : c'est une aide au réglage, qui ne survit pas à
     * un redémarrage et n'a pas à encombrer l'historique. Elle existe pour une
     * raison précise — les codes varient d'un modèle à l'autre, et sans elle il
     * faudrait brancher un terminal sur le portier pour découvrir lesquels il
     * envoie.
     */
    const RAW_KEEP = 50;

    private function pushRaw($_event) {
        $key = __CLASS__ . '::raw::' . $this->getId();
        $raw = cache::byKey($key)->getValue(array());
        if (!is_array($raw)) {
            $raw = array();
        }
        $raw[] = array(
            'time'   => isset($_event['time']) ? $_event['time'] : date('Y-m-d H:i:s'),
            'code'   => isset($_event['code']) ? $_event['code'] : '',
            'action' => isset($_event['action']) ? $_event['action'] : '',
            'index'  => isset($_event['index']) ? $_event['index'] : 0,
            /* Signalé pour ce qu'il est : cette page sert à savoir ce que le
             * portier envoie, et une fin fabriquée par le plugin s'y ferait
             * passer pour une observation. */
            'synthetic' => !empty($_event['synthetic']),
            'data'   => isset($_event['data']) ? $_event['data'] : array(),
        );
        if (count($raw) > self::RAW_KEEP) {
            $raw = array_slice($raw, -self::RAW_KEEP);
        }
        cache::set($key, $raw, 7200);
    }

    public function rawEvents() {
        $raw = cache::byKey(__CLASS__ . '::raw::' . $this->getId())->getValue(array());
        return is_array($raw) ? array_reverse($raw) : array();
    }

    /* ================================================ JOURNAL D'APPELS */

    /*
     * Relit le journal du portier et rattrape ce que le démon n'a pas vu.
     *
     * Le repère est l'horodatage du dernier appel traité, et non le numéro
     * d'enregistrement : ces numéros sont contigus de 1 à N dans CHAQUE réponse,
     * ce qui trahit un indice de position et non un identifiant. Le jour où le
     * tampon circulaire déborde, ils se renumérotent — et un repère fondé sur
     * eux ferait silencieusement rejouer ou manquer des sonneries. CreateTime,
     * lui, est unique sur la totalité du journal.
     */
    public function backfillCalls($_force = false) {
        $interval = (int) config::byKey('call_history_interval', __CLASS__, 15);
        if ($interval <= 0 && !$_force) {
            return null;                      // rattrapage désactivé
        }
        $lastRun = $this->getCache('backfill_run', 0);
        if (!$_force && $lastRun > 0 && (time() - $lastRun) < $interval * 60) {
            return null;
        }
        $this->setCache('backfill_run', time());

        $raw = self::cgiRequest($this, 'recordFinder.cgi?action=find&name=' . dahuavtobeCallLog::LOG_NAME,
                                false, 20, $detail, 'debug');
        if ($raw === false) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' '
                   . __('journal d\'appels illisible', __FILE__));
            return null;
        }

        $calls = array();
        foreach (dahuavtobeCallLog::parse($raw) as $record) {
            $call = dahuavtobeCallLog::normalize($record);
            if ($call !== null) {
                $calls[] = $call;
            }
        }
        if (empty($calls)) {
            return null;
        }
        usort($calls, function ($a, $b) { return $a['time'] - $b['time']; });

        $now  = time();
        $last = end($calls);
        $mark = (int) $this->getConfiguration('last_call_time', 0);

        /*
         * Premier passage : on pose le repère sans rien signaler. Le journal
         * porte des années d'appels, tous antérieurs à l'installation du
         * plugin — les annoncer comme des sonneries manquées serait absurde.
         */
        if ($mark <= 0) {
            $this->rememberCallMark($last['time']);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' '
                   . __('journal d\'appels lu, repère posé au', __FILE__) . ' ' . date('Y-m-d H:i:s', $last['time']));
            $this->publishCallCounters($calls, $now);
            return 0;
        }

        $nouveaux = dahuavtobeCallLog::withoutLive(dahuavtobeCallLog::since($calls, $mark, $now),
                                                   (array) $this->getCache('live_rings', array()));
        $recovered = count($nouveaux);
        foreach ($nouveaux as $call) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' '
                   . __('sonnerie rattrapée du', __FILE__) . ' ' . date('Y-m-d H:i:s', $call['time'])
                   . ($call['missed'] ? ' (' . __('sans réponse', __FILE__) . ')' : ''));
            /* Visible dans l'onglet Diagnostic, et marquée comme reconstituée :
             * elle ne vient pas du flux d'événements. */
            $this->pushRaw(array(
                'time'      => date('Y-m-d H:i:s', $call['time']),
                'code'      => 'CallLogRecovered',
                'action'    => $call['missed'] ? 'Missed' : 'Answered',
                'index'     => 0,
                'synthetic' => true,
                'data'      => array('peer' => $call['peer']),
            ));
        }

        /*
         * Le repère avance dès que le journal a été examiné, même si rien n'a
         * été signalé. Tout ce qui le précède a été soit annoncé, soit écarté en
         * connaissance de cause — le réexaminer à chaque passage ne changerait
         * rien et laisserait le repère traîner indéfiniment derrière la réalité.
         */
        if ($last['time'] > $mark) {
            $this->rememberCallMark($last['time']);
        }

        if ($recovered > 0) {
            /*
             * La date du dernier appel est corrigée, mais la commande Sonnerie
             * n'est PAS actionnée : elle déclenche des scénarios, et rejouer à
             * minuit la sonnerie de l'après-midi ferait s'allumer la maison pour
             * un visiteur reparti depuis longtemps. Le rattrapage informe, il ne
             * fait pas semblant que l'événement vient d'arriver.
             */
            $this->checkAndUpdateCmd('dernier_appel', date('Y-m-d H:i:s', $last['time']));
            log::add(__CLASS__, 'info', $this->getHumanName() . ' '
                   . $recovered . ' ' . __('sonnerie(s) rattrapée(s) dans le journal du portier', __FILE__));
        }

        $this->publishCallCounters($calls, $now);
        return $recovered;
    }

    /* Le repère survit à un redémarrage : il va en configuration, pas en cache.
     * Écriture directe, sinon postSave recréerait les commandes et rechargerait
     * le démon à chaque passage du cron. */
    private function rememberCallMark($_timestamp) {
        $this->setConfiguration('last_call_time', (int) $_timestamp);
        $this->save(true);
    }

    private function publishCallCounters($_calls, $_now) {
        $this->checkAndUpdateCmd('appels_manques_24h', dahuavtobeCallLog::countMissed($_calls, $_now));
    }

    /*
     * Appel manqué annoncé en direct : le compteur avance tout de suite, sans
     * attendre la relecture du journal.
     *
     * Deux signaux y mènent : BackKeyLight à l'état 6, et VideoTalkLog avec
     * EndState « Missed » — le seul des deux que le VTO2211G envoie. Un modèle
     * qui enverrait les deux est couvert par l'anti-rebond. CallNoAnswered n'y
     * mène pas : son Stop n'est pas garanti, et le rattrapage le compte.
     *
     * Aucune requête au portier ici : ce chemin tourne dans la requête qui
     * traite un lot d'événements du démon, et une relecture du journal —
     * jusqu'à vingt secondes — retarderait tout ce qui suit dans le lot.
     */
    private function countLiveMissedCall($_date) {
        $eventTime = strtotime($_date);
        $interval = (int) config::byKey('call_history_interval', __CLASS__, 15);
        if (!dahuavtobeCallLog::liveMissedCounts($eventTime === false ? 0 : $eventTime,
                (int) $this->getCache('missed_live_at', 0), (int) $this->getCache('backfill_run', 0), $interval)) {
            return;
        }
        $cmd = $this->getCmd('info', 'appels_manques_24h');
        if (!is_object($cmd)) {
            return;
        }
        $this->setCache('missed_live_at', $eventTime);
        $this->checkAndUpdateCmd('appels_manques_24h', (int) $cmd->execCmd() + 1);
    }

    /* ========================================================== CRON */

    /*
     * Cron minute : joignabilité du portier, mais seulement quand le démon ne
     * tourne pas.
     *
     * Les deux écrivent sur la même commande, et ils ne parlent pas de la même
     * chose : le démon rend compte de la liaison d'événements, le cron d'une
     * simple requête HTTP. Les laisser cohabiter fait clignoter le témoin —
     * le démon signale la coupure, le cron la nie dans la minute, et
     * l'utilisateur voit un portier qui va et vient sans raison. Le démon a le
     * dernier mot dès qu'il est là : c'est lui qui sait si les événements
     * passent, et c'est la seule chose qui compte pour une sonnette.
     */
    public static function cron() {
        $daemonUp = false;
        try {
            $daemonUp = (self::deamon_info()['state'] == 'ok');
        } catch (Throwable $e) {
            /* Impossible de savoir : on sondera, une information vaut mieux qu'aucune. */
            log::add(__CLASS__, 'debug', __('État du démon indéterminé :', __FILE__) . ' ' . $e->getMessage());
        }

        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                if ($eqLogic->getConfiguration('ip') == '') {
                    continue;
                }
                if (!$daemonUp) {
                    $alive = self::cgiRequest($eqLogic, 'magicBox.cgi?action=getDeviceType', false, 5,
                                              $detail, 'debug') !== false;
                    $eqLogic->checkAndUpdateCmd('en_ligne', $alive ? 1 : 0);
                    if (!$alive) {
                        continue;             // inutile de réclamer son journal à un portier muet
                    }
                }
                /*
                 * Le rattrapage tourne dans les deux cas, et c'est voulu : il ne
                 * sert pas qu'aux coupures. Si le flux d'événements de ce modèle
                 * ne portait pas la sonnerie, le journal du portier resterait la
                 * seule façon de savoir que quelqu'un a sonné. Il se limite tout
                 * seul à son intervalle.
                 */
                $eqLogic->backfillCalls();
            } catch (Throwable $e) {
                /* Un portier en échec ne doit pas priver les autres de leur tour. */
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Page Santé de Jeedom.
     *
     * STATIQUE, et ce n'est pas un détail de style : desktop/php/health.php teste
     * method_exists() puis appelle <plugin>::health() en statique. Une méthode
     * d'instance passe le test et fait lever une Error à l'appel — que le
     * catch (Exception) du coeur ne rattrape pas. Ce n'est alors pas le plugin
     * qui tombe, mais la page Santé de toute l'installation, en erreur 500.
     */
    public static function health() {
        $return = array();

        /*
         * L'état du démon en premier, et une seule fois : sans lui, aucun
         * événement ne peut arriver, quel que soit l'état des portiers.
         */
        $daemon = self::deamon_info();
        $daemonUp = ($daemon['state'] == 'ok');
        $return[] = array(
            'test'   => __('Démon en marche', __FILE__),
            'result' => $daemonUp ? __('OK', __FILE__) : __('NOK', __FILE__),
            'advice' => $daemonUp ? '' : __('Sans démon, aucune sonnerie ne remonte : démarrez-le depuis la configuration du plugin.', __FILE__),
            'state'  => $daemonUp,
        );

        foreach (self::byType(__CLASS__) as $eqLogic) {
            $name = $eqLogic->getHumanName(true);

            $hasIp = $eqLogic->getConfiguration('ip') != '';
            $return[] = array(
                'test'   => $name . ' — ' . __('adresse renseignée', __FILE__),
                'result' => $hasIp ? $eqLogic->getConfiguration('ip') : __('NOK', __FILE__),
                'advice' => $hasIp ? '' : __('Renseignez l\'adresse du portier.', __FILE__),
                'state'  => $hasIp,
            );

            /* Un équipement sans objet parent n'apparaît sur aucun dashboard, et
             * rien d'autre dans Jeedom ne le signale. */
            $hasObject = $eqLogic->getObject_id() != '';
            $return[] = array(
                'test'   => $name . ' — ' . __('rattaché à un objet', __FILE__),
                'result' => $hasObject ? __('OK', __FILE__) : __('NOK', __FILE__),
                'advice' => $hasObject ? '' : __('Sans objet parent, l\'équipement n\'apparaît sur aucun dashboard.', __FILE__),
                'state'  => $hasObject,
            );

            /* Analyse cochée sans clé : l'équipement la demande, mais le démon
             * ne la fera jamais, et rien d'autre ne le dirait. */
            if ((int) $eqLogic->getConfiguration('ai_enable', 0) === 1) {
                $hasKey = trim((string) config::byKey('ai_apikey', __CLASS__, '')) !== '';
                $return[] = array(
                    'test'   => $name . ' — ' . __('analyse des visiteurs', __FILE__),
                    'result' => $hasKey ? __('OK', __FILE__) : __('NOK', __FILE__),
                    'advice' => $hasKey ? '' : __('L\'analyse est cochée sur l\'équipement, mais aucune clé API n\'est renseignée dans la configuration du plugin.', __FILE__),
                    'state'  => $hasKey,
                );
            }

            /*
             * L'état qui compte vraiment : les événements passent-ils ?
             *
             * La commande « En ligne » ne suffit pas à répondre. Démon arrêté,
             * c'est le cron qui l'alimente, et il ne fait qu'une requête HTTP :
             * un portier parfaitement joignable affichait donc « liaison
             * établie » alors que personne n'écoutait. Le portier répond, mais
             * rien ne remonte — les deux conditions sont nécessaires.
             */
            $online = $eqLogic->getCmd(null, 'en_ligne');
            $answers = is_object($online) && $online->execCmd() == 1;
            $listening = $daemonUp && $answers;
            $return[] = array(
                'test'   => $name . ' — ' . __('événements reçus', __FILE__),
                'result' => $listening ? __('OK', __FILE__) : __('NOK', __FILE__),
                'advice' => $listening ? ''
                          : (!$daemonUp ? __('Le démon est arrêté : le portier a beau répondre, personne ne l\'écoute.', __FILE__)
                                        : __('Le portier ne répond pas. Vérifiez son adresse et ses identifiants.', __FILE__)),
                'state'  => $listening,
            );
        }

        return $return;
    }
}

/*
 * La classe de commande est obligatoire, même réduite au minimum : sans elle
 * le coeur refuse de créer et d'ouvrir un équipement.
 */
class dahuavtobeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'capture':
                $eqLogic->takeSnapshot();
                break;

            case 'analyser':
                $eqLogic->analyseNow();
                break;

            case 'ouvrir':
                $eqLogic->openDoor();
                break;

            case 'reconnecter':
                dahuavtobe::sendToDaemon(array('order' => 'reconnect', 'id' => (int) $eqLogic->getId()));
                break;
        }
        return true;
    }
}
