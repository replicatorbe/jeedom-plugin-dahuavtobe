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


require_once __DIR__ . '/../../../core/php/core.inc.php';

/* Crée les dossiers produits à l'exécution et les protège d'Apache. */
function dahuavtobe_prepareData() {
    $dirs = array(__DIR__ . '/../data', __DIR__ . '/../data/snapshots');
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    $htaccess = __DIR__ . '/../data/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    }
}

function dahuavtobe_install() {
    dahuavtobe_prepareData();
    /* Le callback du démon n'a aucune raison d'être joignable depuis
     * l'extérieur : il ne parle qu'à un processus local. */
    config::save('api::dahuavtobe::mode', 'localhost', 'core');
}

/*
 * Appelée à chaque mise à jour, dans la requête HTTP et sans être détachée :
 * aucun appel réseau ici, sous peine de faire expirer la page « Gestion des
 * plugins ». Tout ce qui s'y trouve doit être hors ligne et rapide.
 */
function dahuavtobe_update() {
    try {
        dahuavtobe_prepareData();
        config::save('api::dahuavtobe::mode', 'localhost', 'core');
        foreach (dahuavtobe::byType('dahuavtobe') as $eqLogic) {
            $eqLogic->createCommands();
        }
        dahuavtobe_migrateSnapshotWidget();
        dahuavtobe_migrateOpenDoorConfirm();
    } catch (Throwable $e) {
        log::add('dahuavtobe', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

/*
 * La commande d'adresse RTSP disparaît, et la photo passe sur son gabarit.
 *
 * Gardée par une clé de configuration posée seulement en cas de succès complet :
 * une migration à moitié faite doit pouvoir être retentée, et une migration
 * réussie ne doit pas défaire à chaque mise à jour ce que l'utilisateur aurait
 * réglé depuis.
 */
function dahuavtobe_migrateSnapshotWidget() {
    if (config::byKey('migration::snapshot_widget', 'dahuavtobe', 0) == 1) {
        return;
    }
    foreach (dahuavtobe::byType('dahuavtobe') as $eqLogic) {
        /* L'adresse RTSP n'était utilisable qu'en y inscrivant le mot de passe
         * du portier : la commande est retirée plutôt que laissée en place. */
        $rtsp = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), 'rtsp');
        if (is_object($rtsp)) {
            $rtsp->remove();
        }
        $snapshot = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), 'snapshot');
        if (is_object($snapshot) && $snapshot->getTemplate('dashboard') != 'dahuavtobe::dahuavtobe') {
            $snapshot->setTemplate('dashboard', 'dahuavtobe::dahuavtobe');
            $snapshot->setTemplate('mobile', 'dahuavtobe::dahuavtobe');
            $snapshot->save();
        }
    }
    config::save('migration::snapshot_widget', 1, 'dahuavtobe');
}

/*
 * L'ouverture de la porte demande désormais confirmation au clic. createCommands()
 * ne touche pas une commande qui existe déjà : les portiers créés avant n'en
 * profiteraient jamais sans ce rattrapage.
 *
 * Une seule fois, comme la migration précédente : l'utilisateur qui décoche
 * ensuite la confirmation l'a voulu, et une mise à jour n'a pas à la lui remettre.
 */
function dahuavtobe_migrateOpenDoorConfirm() {
    if (config::byKey('migration::open_door_confirm', 'dahuavtobe', 0) == 1) {
        return;
    }
    foreach (dahuavtobe::byType('dahuavtobe') as $eqLogic) {
        $open = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), 'ouvrir');
        if (is_object($open) && $open->getConfiguration('actionConfirm') != 1) {
            $open->setConfiguration('actionConfirm', 1);
            $open->save();
        }
    }
    config::save('migration::open_door_confirm', 1, 'dahuavtobe');
}

/*
 * Appelée aussi à la simple désactivation du plugin, pas seulement à sa
 * désinstallation : ne rien y détruire d'irrécupérable.
 */
function dahuavtobe_remove() {
    try {
        dahuavtobe::deamon_stop();
    } catch (Throwable $e) {
        log::add('dahuavtobe', 'error', __('Arrêt du démon :', __FILE__) . ' ' . $e->getMessage());
    }
}
