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

/*
 * Point d'entrée du démon.
 *
 * Trois routes, toutes protégées par la clé API du plugin :
 *   GET  ?apikey=…&test=1        → 'OK', joignabilité vérifiée au démarrage
 *   GET  ?apikey=…&action=config → la configuration, en JSON
 *   POST ?apikey=…  {"events":[…]} → un lot d'événements, répond 'OK'
 *
 * Un lot porte des événements du portier, et quatre types produits par le
 * démon : 'status' (liaison), 'door' (relevé de la gâche), 'snapshot'
 * (photo prise) et 'analysis' (qui a sonné).
 *
 * L'API du plugin est en mode « localhost » (posée à l'installation) : elle ne
 * répond qu'en boucle locale, et c'est le seul interlocuteur qu'elle ait.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/*
 * 401 et non 200 sur refus : le démon ne dispose que du code HTTP pour savoir si
 * son lot a été pris. Un 200 le ferait jeter des événements en les croyant
 * livrés.
 */
if (!jeedom::apiAccess(init('apikey'), 'dahuavtobe')) {
    http_response_code(401);
    echo 'Not authorized';
    die();
}

if (init('test') != '') {
    echo 'OK';
    die();
}

if (init('action') == 'config') {
    header('Content-Type: application/json');
    echo json_encode(dahuavtobe::getDaemonConfig());
    die();
}

$body = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
    log::add('dahuavtobe', 'error', __('Lot d\'événements illisible.', __FILE__));
    echo 'KO';
    die();
}

/*
 * Les portiers sont résolus une fois pour tout le lot : un appel qui sonne
 * produit une demi-douzaine d'événements en moins d'une seconde, et chacun
 * repartirait sinon chercher le même équipement en base.
 */
$stations = array();
foreach (dahuavtobe::byType('dahuavtobe', true) as $eqLogic) {
    $stations[(int) $eqLogic->getId()] = $eqLogic;
}

foreach ($payload['events'] as $event) {
    if (!is_array($event)) {
        continue;
    }
    $id = isset($event['station_id']) ? (int) $event['station_id'] : 0;
    if (!isset($stations[$id])) {
        /* Portier supprimé ou désactivé pendant que le lot voyageait : le démon
         * l'apprendra à son prochain rechargement, il n'y a rien à signaler. */
        continue;
    }
    $station = $stations[$id];

    try {
        $type = isset($event['type']) ? $event['type'] : '';

        if ($type == 'status') {
            $connected = (isset($event['status']) && $event['status'] == 'connected');
            $station->checkAndUpdateCmd('en_ligne', $connected ? 1 : 0);
            if ($connected) {
                /* init() lit un paramètre de la requête, pas une valeur de
                 * tableau : l'utiliser ici rendait toujours la valeur par
                 * défaut, et le journal annonçait « connecté en ? ». */
                $transport = isset($event['transport']) ? (string) $event['transport'] : '?';
                log::add('dahuavtobe', 'info', $station->getHumanName() . ' '
                       . __('connecté en', __FILE__) . ' ' . $transport);
            } else {
                $reason = isset($event['error']) ? (string) $event['error'] : '';
                log::add('dahuavtobe', 'warning', $station->getHumanName() . ' '
                       . __('déconnecté :', __FILE__) . ' ' . $reason);
            }
            continue;
        }

        /*
         * Relevé de l'état de la gâche, demandé par le démon à la connexion.
         * Le portier n'annonce que les changements : sans ce relevé, la commande
         * resterait sur sa valeur d'usine sans que rien ne l'ait vérifié.
         */
        if ($type == 'door') {
            $station->checkAndUpdateCmd('porte', !empty($event['open']) ? 1 : 0);
            continue;
        }

        if ($type == 'snapshot') {
            /*
             * Une capture ratée ne met rien à jour : la commande Image garde la
             * photo précédente, faute de mieux. Mais elle le dit, sans quoi
             * l'image d'une visite passée se ferait passer pour celle du
             * visiteur en train de sonner.
             */
            if (isset($event['ok']) && !$event['ok']) {
                log::add('dahuavtobe', 'warning', $station->getHumanName() . ' '
                       . __('capture impossible : l\'image affichée est celle de la visite précédente.', __FILE__));
                $station->applyEvent(array(
                    'code'   => 'SnapshotFailed',
                    'action' => 'Pulse',
                    'index'  => 0,
                    'data'   => array(),
                    'time'   => isset($event['time']) ? $event['time'] : date('Y-m-d H:i:s'),
                ));
                continue;
            }
            $file = isset($event['file']) ? (string) $event['file'] : '';
            /* Le nom vient du démon, mais il compose une URL : même liste
             * blanche que le passe-plat qui servira l'image. */
            if (preg_match(dahuavtobe::SNAPSHOT_PATTERN, $file) !== 1) {
                log::add('dahuavtobe', 'error', __('Nom de capture refusé :', __FILE__) . ' ' . $file);
                continue;
            }
            $station->checkAndUpdateCmd('snapshot', dahuavtobe::snapshotUrl($file));
            $station->checkAndUpdateCmd('snapshot_file', dahuavtobe::snapshotPath($file));
            continue;
        }

        /*
         * Qui a sonné, d'après les photos de la visite. Le démon envoie cet
         * événement après chaque sonnerie d'un portier où l'analyse est active,
         * réussite ou échec : une visite doit toujours recevoir une réponse.
         * Les noms d'images sont vérifiés par applyAnalysis(), comme ci-dessus.
         */
        if ($type == 'analysis') {
            $station->applyAnalysis($event);
            continue;
        }

        $station->applyEvent($event);
    } catch (Throwable $e) {
        /* Un événement en échec ne doit pas emporter le reste du lot : la
         * sonnerie qui suit vaut mieux que la cohérence d'un lot. */
        log::add('dahuavtobe', 'error', $station->getHumanName() . ' : ' . $e->getMessage());
    }
}

echo 'OK';
