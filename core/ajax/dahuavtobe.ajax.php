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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil : isConnect('user')
     * serait faux pour un administrateur. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    /* Depuis la 4.4, ajax::getToken() rend une chaîne vide : contrôler un jeton
     * casserait l'appel. L'authentification repose sur la session. */
    ajax::init();

    /*
     * eqLogic::byId() caste n'importe quel équipement vers la classe appelante :
     * un identifiant étranger produirait une erreur fatale plus loin, dans une
     * méthode qui n'existe pas. Le contrôle de type est obligatoire.
     */
    $getStation = function ($_id) {
        $eqLogic = eqLogic::byId(init($_id));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'dahuavtobe') {
            throw new Exception(__('Portier introuvable :', __FILE__) . ' ' . init($_id));
        }
        return $eqLogic;
    };

    if (init('action') == 'testConnection') {
        /* Le test interroge l'appareil et retient son modèle : c'est une
         * écriture, donc interdite en mode démonstration. */
        unautorizedInDemo();
        $station = $getStation('id');
        ajax::success($station->testConnection());
    }

    if (init('action') == 'snapshot') {
        unautorizedInDemo();
        $station = $getStation('id');
        $name = $station->takeSnapshot();
        ajax::success(array('url' => dahuavtobe::snapshotUrl($name) . '&t=' . time()));
    }

    /* Analyse de la dernière photo : met à jour les commandes du visiteur et
     * joue les actions de sa catégorie, comme une vraie sonnerie. C'est
     * l'usage voulu — tester la chaîne jusqu'à la notification. */
    if (init('action') == 'analyse') {
        unautorizedInDemo();
        $station = $getStation('id');
        ajax::success($station->analyseNow());
    }

    if (init('action') == 'daemonStatus') {
        $info = dahuavtobe::deamon_info();
        $answer = dahuavtobe::sendToDaemon(array('order' => 'status'), true);
        /* Le démon répond {state, result} : seul result nous intéresse, et il
         * est absent si le démon est arrêté ou n'a pas répondu à temps. */
        $status = (is_array($answer) && isset($answer['result'])) ? $answer['result'] : null;
        ajax::success(array('deamon' => $info, 'status' => $status));
    }

    /* Les derniers événements bruts, pour l'onglet Diagnostic. C'est la seule
     * façon de découvrir ce qu'un modèle envoie réellement quand on sonne. */
    if (init('action') == 'rawEvents') {
        $station = $getStation('id');
        ajax::success($station->rawEvents());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
    /*
     * Throwable et non Exception : les erreurs de PHP 8 n'héritent pas
     * d'Exception, et un appel non rattrapé rendrait un HTTP 500 sans corps
     * JSON — que le JS du coeur réessaie trois fois avant d'abandonner.
     */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
