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
 * Sert une capture du portier à un utilisateur connecté.
 *
 * data/ est interdit d'accès par Apache, et c'est souhaitable : l'image d'un
 * visiteur devant sa porte n'a pas à être lisible sans authentification. Ce
 * passe-plat la délivre après contrôle de session.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/dahuavtobe.class.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
    header('HTTP/1.0 401 Unauthorized');
    die('401 - Unauthorized');
}

$file = init('file');

/*
 * Le nom est imposé par le plugin (vto<id>_<date>_<jeton>.jpg). Une expression
 * stricte ferme toute traversée de répertoire : ni '/', ni '..' ne passent.
 *
 * is_string avant tout : init() rend le paramètre tel qu'il arrive, et un
 * « ?file[] » passerait un tableau à preg_match, donc une erreur fatale.
 * Le modificateur D ferme l'autre bout — sans lui, « $ » tolère un saut de
 * ligne final.
 */
if (!is_string($file) || !preg_match('/^vto(\d+)_\d{8}-\d{6}_[0-9a-f]{8}\.jpg$/D', $file, $m)) {
    header('HTTP/1.0 400 Bad Request');
    die('400 - Bad Request');
}

/*
 * Le secret de l'URL ne tient pas lieu d'autorisation.
 *
 * isConnect() seul est nécessaire — l'image est rendue sur le dashboard
 * d'utilisateurs qui ne sont pas administrateurs, exiger isConnect('admin') la
 * casserait. Mais il ne suffit pas : sans ce contrôle, n'importe quel compte de
 * l'installation lirait l'image de n'importe quel portier dès qu'il en connaît
 * l'URL, y compris un profil restreint qui n'a droit à aucun de ces
 * équipements. Or il s'agit d'une caméra braquée sur une porte d'entrée.
 *
 * L'identifiant est repris du nom de fichier, que le plugin a lui-même composé
 * et dont l'expression ci-dessus garantit la forme : il n'y a rien à assainir.
 */
$station = eqLogic::byId((int) $m[1]);
if (!is_object($station) || $station->getEqType_name() != 'dahuavtobe' || !$station->hasRight('r')) {
    header('HTTP/1.0 403 Forbidden');
    die('403 - Forbidden');
}

$path = dahuavtobe::snapshotDir() . '/' . $file;

if (!is_file($path)) {
    /* Rotation : les images les plus anciennes disparaissent. C'est le cours
     * normal des choses, pas une erreur serveur. */
    header('HTTP/1.0 404 Not Found');
    die('404 - Not Found');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
