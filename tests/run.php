<?php
/* Rejeu hors ligne du journal d'appels du portier.
 *
 *   php tests/run.php
 *
 * Les fixtures de tests/fixtures/ sont des réponses réelles d'un
 * DHI-VTO2211G-WP en micrologiciel 4.511, capturées telles quelles — fins de
 * ligne CRLF comprises. Elles permettent de vérifier le découpage et la
 * conversion des horodatages sans dépendre d'un portier joignable, et de figer
 * ce qu'on a observé : le jour où une réponse change de forme, c'est ici que ça
 * se verra, et non sur le dashboard d'un utilisateur. */

/* Aucune dépendance : ni Jeedom, ni base de données, ni portier joignable.
 * Le fuseau est fixé explicitement, parce que la conversion des horodatages en
 * dépend — un jeu d'essai dont le résultat change avec le php.ini de la machine
 * ne prouverait rien. */
date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/dahuavtobeCallLog.class.php';
require_once __DIR__ . '/../core/class/dahuavtobeVision.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    /* Comparaison lâche : le coeur rend ses valeurs tantôt en entier, tantôt en
     * chaîne, et un test qui échoue sur « 1 » contre « '1' » ne dit rien
     * d'utile — il apprend seulement à se méfier de ses propres essais. */
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-52s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-52s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

$fixture = file_get_contents(__DIR__ . '/fixtures/videotalklog-full.txt');
$records = dahuavtobeCallLog::parse($fixture);

echo "\n== Découpage de la réponse ==\n";
verifie('nombre d\'enregistrements', count($records), 306);
verifie('nombre de champs par enregistrement', count($records[0]), 12);
verifie('premier RecNo', $records[0]['RecNo'], 1);
verifie('dernier RecNo', $records[305]['RecNo'], 306);
verifie('un champ vide reste présent', array_key_exists('Notes', $records[0]), true);
verifie('valeur d\'un champ vide', $records[0]['Notes'], '');
verifie('type d\'appel', $records[305]['CallType'], 'Outgoing');
verifie('état de fin', $records[305]['EndState'], 'Missed');

echo "\n== Formes dégradées ==\n";
verifie('réponse vide', count(dahuavtobeCallLog::parse('')), 0);
verifie('page d\'erreur HTML', count(dahuavtobeCallLog::parse('<html>Error</html>')), 0);
verifie('journal vide (found=0)', count(dahuavtobeCallLog::parse("found=0\r\n")), 0);
verifie('fins de ligne en LF seul',
        count(dahuavtobeCallLog::parse("found=1\nrecords[0].CreateTime=1787999464\n")), 1);
/* Une valeur peut contenir un signe égal : on ne coupe qu'au premier, sinon la
 * valeur serait tronquée sans que rien ne le signale. */
$avecEgal = dahuavtobeCallLog::parse("records[0].Notes=a=b=c\r\n");
verifie('valeur contenant un signe égal', $avecEgal[0]['Notes'], 'a=b=c');
$troue = dahuavtobeCallLog::parse("records[2].RecNo=3\r\nrecords[0].RecNo=1\r\n");
verifie('enregistrements remis dans l\'ordre', $troue[0]['RecNo'], 1);

echo "\n== Horodatage : heure murale écrite dans un champ epoch ==\n";
/* Le portier écrit son heure MURALE dans CreateTime. Lu naïvement comme un
 * epoch, le dernier appel du journal tombe deux heures trop tard — et trois
 * enregistrements du journal d'accès se retrouvaient dans le futur. */
verifie('dernier appel du journal, converti',
        date('Y-m-d H:i:s', dahuavtobeCallLog::time(1787999464)), '2026-08-29 10:31:04');
verifie('lecture naïve, pour mémoire (fausse)',
        date('Y-m-d H:i:s', 1787999464), '2026-08-29 12:31:04');
/* Les deux premiers enregistrements du portier datent de sa toute première mise
 * sous tension, horloge non réglée. Ils doivent traverser le découpage sans
 * faire tomber quoi que ce soit — c'est la garde d'âge qui les écartera. */
verifie('horloge jamais réglée : la conversion tient quand même',
        date('Y', dahuavtobeCallLog::time(946685099)), '2000');
verifie('CreateTime nul', dahuavtobeCallLog::time(0), null);
verifie('CreateTime absurde', dahuavtobeCallLog::time(-1), null);

echo "\n== Reconnaissance d'une sonnerie ==\n";
$appel = dahuavtobeCallLog::normalize($records[305]);
verifie('un appel sortant est une sonnerie', is_array($appel), true);
verifie('sans réponse', $appel['missed'], true);
verifie('correspondant', $appel['peer'], '9901');
verifie('un appel entrant n\'en est pas une',
        dahuavtobeCallLog::normalize(array('CreateTime' => 1787999464, 'CallType' => 'Incoming')), null);
verifie('sans horodatage, on ne conclut rien',
        dahuavtobeCallLog::normalize(array('CallType' => 'Outgoing')), null);
verifie('décroché : TalkTime non nul et EndState Received',
        dahuavtobeCallLog::normalize(array('CreateTime' => 1787999464, 'CallType' => 'Outgoing',
                                        'EndState' => 'Received', 'TalkTime' => 12))['missed'], false);
verifie('TalkTime nul suffit à conclure au non-décroché',
        dahuavtobeCallLog::normalize(array('CreateTime' => 1787999464, 'CallType' => 'Outgoing',
                                        'EndState' => 'Received', 'TalkTime' => 0))['missed'], true);

echo "\n== Le journal dans son ensemble ==\n";
$appels = array();
foreach ($records as $record) {
    $normalise = dahuavtobeCallLog::normalize($record);
    if ($normalise !== null) {
        $appels[] = $normalise;
    }
}
verifie('toutes les entrées sont des sonneries', count($appels), 306);
$horodatages = array();
foreach ($appels as $a) {
    $horodatages[] = $a['time'];
}
verifie('horodatages tous distincts', count(array_unique($horodatages)), 306);
$croissant = true;
for ($i = 1; $i < count($horodatages); $i++) {
    if ($horodatages[$i] <= $horodatages[$i - 1]) {
        $croissant = false;
    }
}
verifie('journal rendu du plus ancien au plus récent', $croissant, true);
verifie('aucune sonnerie dans le futur', max($horodatages) <= time(), true);

echo "\n== Rattrapage : que signale-t-on, et que tait-on ? ==\n";
$maintenant = 1789300000;                      // instant de référence fixe
$journal = array(
    array('time' => $maintenant - 400000, 'missed' => true),   // il y a ~4,6 jours
    array('time' => $maintenant - 90000,  'missed' => true),   // il y a 25 h
    array('time' => $maintenant - 3600,   'missed' => true),   // il y a 1 h
    array('time' => $maintenant - 600,    'missed' => false),  // il y a 10 min, décroché
    array('time' => $maintenant + 7200,   'missed' => true),   // dans le futur : horloge déréglée
);

/* Sans repère, c'est la garde d'âge qui fait tout le travail : les trois appels
 * des 48 dernières heures passent — 25 h, 1 h et 10 min — l'archive et le futur
 * sont écartés. En production ce cas ne se présente pas : le tout premier
 * passage pose le repère sans rien annoncer. C'est bien le comportement de la
 * garde qu'on fige ici, pas celui du rattrapage. */
$sansRepere = dahuavtobeCallLog::since($journal, 0, $maintenant);
verifie('sans repère, la fenêtre de 48 h laisse passer trois appels', count($sansRepere), 3);

$depuisHier = dahuavtobeCallLog::since($journal, $maintenant - 7200, $maintenant);
verifie('repère à 2 h : deux appels plus récents', count($depuisHier), 2);
verifie('le plus ancien des deux est celui d\'il y a 1 h',
        $depuisHier[0]['time'], $maintenant - 3600);

verifie('un appel déjà connu ne repasse pas',
        count(dahuavtobeCallLog::since($journal, $maintenant - 60, $maintenant)), 0);
verifie('l\'archive reste tue (garde de 48 h)',
        count(dahuavtobeCallLog::since(array($journal[0]), 0, $maintenant)), 0);
verifie('un appel daté du futur est écarté',
        count(dahuavtobeCallLog::since(array($journal[4]), 0, $maintenant)), 0);
verifie('journal vide', count(dahuavtobeCallLog::since(array(), 0, $maintenant)), 0);

echo "\n== Comptage des sonneries sans réponse ==\n";
verifie('sur 24 h', dahuavtobeCallLog::countMissed($journal, $maintenant), 1);
verifie('sur 7 jours', dahuavtobeCallLog::countMissed($journal, $maintenant, 604800), 3);
verifie('un appel décroché ne compte pas',
        dahuavtobeCallLog::countMissed(array($journal[3]), $maintenant), 0);
verifie('le futur ne compte pas',
        dahuavtobeCallLog::countMissed(array($journal[4]), $maintenant, 604800), 0);

echo "\n== Appel manqué annoncé en direct ==\n";
/* L'incrément direct n'est qu'une avance sur le rattrapage : chaque refus
 * ci-dessous protège contre un chiffre faux, jamais contre un chiffre en
 * retard — le journal rattrape toujours ce qui a été refusé ici. */
verifie('rattrapage actif : le compteur avance',
        dahuavtobeCallLog::liveMissedCounts($maintenant, 0, $maintenant - 600, 15), true);
verifie('rattrapage désactivé : il ne bouge pas',
        dahuavtobeCallLog::liveMissedCounts($maintenant, 0, $maintenant - 600, 0), false);
verifie('la même annonce redite 20 s plus tard ne recompte pas',
        dahuavtobeCallLog::liveMissedCounts($maintenant + 20, $maintenant, $maintenant - 600, 15), false);
verifie('un nouvel appel deux minutes après, si',
        dahuavtobeCallLog::liveMissedCounts($maintenant + 120, $maintenant, $maintenant - 600, 15), true);
verifie('journal relu après l\'appel : déjà compté',
        dahuavtobeCallLog::liveMissedCounts($maintenant, 0, $maintenant + 5, 15), false);

/* Le journal réel du portier, passé au même tamis : rien ne doit remonter,
 * la dernière sonnerie datant de plusieurs jours. */
$reels = array();
foreach ($records as $record) {
    $n = dahuavtobeCallLog::normalize($record);
    if ($n !== null) { $reels[] = $n; }
}
verifie('journal réel : aucune sonnerie à rattraper aujourd\'hui',
        count(dahuavtobeCallLog::since($reels, 0, time())), 0);

/* Et la preuve inverse, sur les mêmes données réelles : replacé à l'instant où
 * la dernière sonnerie a eu lieu, le rattrapage la retrouve. C'est le chemin
 * complet — réponse brute du portier, découpage, conversion d'horodatage,
 * sélection — sans avoir à faire sonner qui que ce soit. */
$dernier = end($reels);
$justeAvant = $dernier['time'] - 1;
$aussitotApres = $dernier['time'] + 60;
verifie('journal réel : la dernière sonnerie est bien retrouvée',
        count(dahuavtobeCallLog::since($reels, $justeAvant, $aussitotApres)), 1);
verifie('journal réel : et une seule fois',
        count(dahuavtobeCallLog::since($reels, $dernier['time'], $aussitotApres)), 0);
/* Deux sonneries se suivent à quelques heures d'intervalle en fin de journal :
 * les deux tombent dans la fenêtre de 24 h qui précède la seconde. Ce chiffre
 * vient du journal, il n'a pas été choisi. */
verifie('journal réel : deux sonneries dans la même fenêtre de 24 h',
        dahuavtobeCallLog::countMissed($reels, $aussitotApres), 2);

echo "\n== Sonneries déjà vues en direct ==\n";
/* Le 17/09, la sonnerie de 17:06:19 a été traitée en direct, puis annoncée
 * « rattrapée » dix minutes plus tard. C'est ce que ce filtre empêche. */
verifie('sonnerie vue en direct : pas rattrapée',
        count(dahuavtobeCallLog::withoutLive(array($dernier), array($dernier['time'] - 3))), 0);
verifie('horloge du portier en avance d\'une minute : toujours reconnue',
        count(dahuavtobeCallLog::withoutLive(array($dernier), array($dernier['time'] + 60))), 0);
verifie('sonnerie vue il y a dix minutes : l\'autre est rattrapée',
        count(dahuavtobeCallLog::withoutLive(array($dernier), array($dernier['time'] - 600))), 1);
verifie('rien vu en direct : tout est rattrapé',
        count(dahuavtobeCallLog::withoutLive(array($dernier), array())), 1);


/* =================================================== ANALYSE DES VISITEURS */

echo "\n== Visiteur : lecture de la réponse ==\n";
$reponse = function ($_json) {
    return array('model' => 'm', 'choices' => array(array('message' => array('content' => $_json))));
};
$r = dahuavtobeVision::parse($reponse('{"categorie":"livreur","confiance":0.87,"description":"Colis en main","indices":["colis","gilet"],"meilleure_image":2}'), 3);
verifie('réponse conforme : acceptée', $r['ok'], true);
verifie('réponse conforme : catégorie', $r['categorie'], 'livreur');
verifie('confiance ramenée en pourcentage', $r['confiance'], 87);
verifie('meilleure photo, comptée à partir de 0', $r['meilleure'], 1);
verifie('indices conservés', implode('|', $r['indices']), 'colis|gilet');
$r = dahuavtobeVision::parse($reponse('{"categorie":"livreur","confiance":0.9,"description":"","indices":[],"meilleure_image":7}'), 3);
verifie('meilleure photo hors bornes : la première', $r['meilleure'], 0);
$r = dahuavtobeVision::parse($reponse('{"categorie":"livreur","confiance":90,"description":"","indices":[],"meilleure_image":1}'), 1);
verifie('confiance donnée en pourcentage : comprise', $r['confiance'], 90);
$r = dahuavtobeVision::parse($reponse('{"categorie":"livreur","confiance":4.2,"description":"","indices":[],"meilleure_image":1}'), 1);
verifie('confiance absurde : plafonnée', $r['confiance'], 4);
/* Une catégorie hors liste ferait taire tous les scénarios sans rien dire :
 * elle doit devenir un échec explicite. */
$r = dahuavtobeVision::parse($reponse('{"categorie":"cambrioleur","confiance":0.9,"description":"","indices":[],"meilleure_image":1}'), 1);
verifie('catégorie inventée : refusée', $r['ok'], false);
verifie('catégorie inventée : indéterminé', $r['categorie'], 'indetermine');
$r = dahuavtobeVision::parse($reponse('C\'est un livreur.'), 1);
verifie('texte libre : refusé', $r['ok'], false);
$r = dahuavtobeVision::parse($reponse("```json\n{\"categorie\":\"vide\",\"confiance\":1,\"description\":\"\",\"indices\":[],\"meilleure_image\":1}\n```"), 1);
verifie('JSON dans une clôture Markdown : accepté', $r['categorie'], 'vide');
verifie('réponse illisible : indéterminé', dahuavtobeVision::parse('<html>', 1)['categorie'], 'indetermine');

echo "\n== Visiteur : charge envoyée ==\n";
verifie('modèle récent : max_completion_tokens', dahuavtobeVision::profil('gpt-6-luna')['plafond'], 'max_completion_tokens');
verifie('modèle récent : sans réflexion', dahuavtobeVision::profil('gpt-6-luna')['reflexion'], 'none');
verifie('modèle local : paramètres classiques', dahuavtobeVision::profil('qwen2.5vl:7b')['plafond'], 'max_tokens');
verifie('modèle derrière une passerelle', dahuavtobeVision::profil('openai/gpt-5.4-mini')['plafond'], 'max_completion_tokens');
$charge = dahuavtobeVision::payload(array('jpeg1', 'jpeg2'), array('model' => 'qwen2.5vl:7b'));
verifie('deux photos, deux images dans la charge', count(array_filter($charge['messages'][1]['content'],
        function ($c) { return $c['type'] === 'image_url'; })), 2);
verifie('température nulle : un tri, pas une rédaction', $charge['temperature'], 0);
verifie('schéma strict', $charge['response_format']['json_schema']['strict'], true);
verifie('le schéma liste les six catégories',
        count($charge['response_format']['json_schema']['schema']['properties']['categorie']['enum']), 6);
verifie('définition élevée par défaut', $charge['messages'][1]['content'][2]['image_url']['detail'], 'high');
verifie('indication de l\'occupant reprise dans l\'invite',
        strpos(dahuavtobeVision::payload(array('x'), array('context' => 'Camionnette en face.'))['messages'][0]['content'],
               'Camionnette en face.') !== false, true);
verifie('invite en anglais si Jeedom l\'est',
        strpos(dahuavtobeVision::prompt(array('language' => 'en_US'), 1), 'en anglais') !== false, true);
verifie('délai vide : défaut', dahuavtobeVision::delai(''), dahuavtobeVision::TIMEOUT_DEFAUT);
verifie('délai trop court : relevé', dahuavtobeVision::delai(1), dahuavtobeVision::TIMEOUT_MIN);
verifie('délai trop long : abaissé', dahuavtobeVision::delai(600), dahuavtobeVision::TIMEOUT_MAX);

echo "\n== Visiteur : échanges avec un faux service ==\n";
/* Le vrai chemin — fichier, encodage, HTTP, relances, lecture — contre un
 * serveur local qui joue chaque panne. Aucun appel ne quitte la machine. */
$image = tempnam(sys_get_temp_dir(), 'vto') . '.jpg';
$gd = imagecreatetruecolor(160, 120);
for ($i = 0; $i < 400; $i++) {
    imagesetpixel($gd, mt_rand(0, 159), mt_rand(0, 119), mt_rand(0, 0xFFFFFF));
}
imagejpeg($gd, $image, 90);
$port = 18000 + mt_rand(0, 999);
$serveur = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/fake-openai.php'),
                     array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $tubes);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}
@unlink(sys_get_temp_dir() . '/dahuavtobe-fake-panne');
$essai = function ($_modele, $_images = null, $_timeout = 10) use ($image, $port) {
    return dahuavtobeVision::analyse($_images === null ? array($image) : $_images, array(
        'base_url' => 'http://127.0.0.1:' . $port . '/v1/', 'apikey' => 'sk-test', 'model' => $_modele, 'timeout' => $_timeout));
};

$r = $essai('ok');
verifie('réponse normale : livreur', $r['categorie'], 'livreur');
verifie('réponse normale : modèle rapporté', $r['modele'], 'ok-2026');
$envoye = json_decode(file_get_contents(sys_get_temp_dir() . '/dahuavtobe-fake-derniere.json'), true);
verifie('la photo part en JPEG base64',
        strpos($envoye['messages'][1]['content'][2]['image_url']['url'], 'data:image/jpeg;base64,/9j/') === 0, true);
verifie('un modèle qui refuse max_tokens : corrigé', $essai('refuse-max-tokens')['categorie'], 'livreur');
verifie('un serveur sans sorties structurées : repli JSON', $essai('sans-schema')['categorie'], 'livreur');
verifie('une panne passagère : une relance, et ça passe', $essai('panne-puis-ok')['ok'], true);
$r = $essai('cle-refusee');
verifie('clé refusée : indéterminé', $r['categorie'], 'indetermine');
verifie('clé refusée : dit pourquoi', $r['erreur'], 'clé API refusée');
verifie('modèle inconnu : dit pourquoi', strpos($essai('inexistant')['erreur'], 'modèle ou adresse inconnus') === 0, true);
verifie('texte libre du modèle : indéterminé', $essai('hors-format')['categorie'], 'indetermine');
verifie('catégorie inventée par le modèle : indéterminé', $essai('categorie-inconnue')['categorie'], 'indetermine');
$r = $essai('pourcent');
verifie('confiance en pourcentage : comprise', $r['confiance'], 85);
verifie('meilleure photo absurde : la première', $r['meilleure'], 0);
verifie('refus du modèle : indéterminé', $essai('refus')['erreur'], 'le modèle a refusé l\'analyse : Je ne peux pas aider.');
verifie('aucune photo lisible : pas d\'appel', $essai('ok', array('/nulle/part.jpg'))['erreur'], 'aucune photo exploitable');
$r = dahuavtobeVision::analyse(array($image), array('apikey' => ''));
verifie('pas de clé : pas d\'appel', $r['erreur'], 'aucune clé API n\'est renseignée');
$debut = microtime(true);
$r = $essai('lent', null, 5);
verifie('service trop lent : abandon au délai', $r['erreur'], 'pas de réponse en 5 s');
verifie('et pas plus tard', (microtime(true) - $debut) < 6.5, true);

proc_terminate($serveur);
@unlink($image);
@unlink(sys_get_temp_dir() . '/dahuavtobe-fake-derniere.json');

echo "\n  ==> $ok réussis, $ko échec(s)\n\n";
exit($ko === 0 ? 0 : 1);
