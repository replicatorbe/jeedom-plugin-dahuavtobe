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
 * Exécute, hors de toute requête HTTP, les actions associées à la catégorie
 * d'un visiteur.
 *
 *   php jeeDahuaVtoActions.php key=<clé de cache>
 *
 * Lancé par dahuavtobe::scheduleVisitorActions(). La clé désigne une entrée de
 * cache qui porte le portier, la catégorie et les tags : rien d'autre ne passe
 * par la ligne de commande, lisible par tout utilisateur local.
 */

/* Ligne de commande seulement : ce fichier est dans la racine web, et une
 * requête HTTP n'a aucune raison de pouvoir déclencher des notifications. */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

require_once __DIR__ . '/../../../../core/php/core.inc.php';

$key = '';
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, 'key=') === 0) {
        $key = substr($argument, 4);
    }
}
/* Seules les clés posées par le plugin sont lues : le cache de Jeedom contient
 * bien d'autres choses. */
if (strpos($key, 'dahuavtobe::actions::') !== 0) {
    exit(1);
}

$cache = cache::byKey($key);
$job = json_decode((string) $cache->getValue(''), true);
/* Lue une seule fois : un second lancement avec la même clé ne rejoue rien. */
$cache->remove();
if (!is_array($job) || !isset($job['id'], $job['category'], $job['tags'])) {
    log::add('dahuavtobe', 'error', __('Actions du visiteur introuvables : leur préparation a expiré.', __FILE__));
    exit(1);
}

$eqLogic = dahuavtobe::byId((int) $job['id']);
if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'dahuavtobe' || !$eqLogic->getIsEnable()) {
    exit(0);
}

try {
    $eqLogic->runVisitorActions((string) $job['category'], (array) $job['tags']);
} catch (Throwable $e) {
    log::add('dahuavtobe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
    exit(1);
}
