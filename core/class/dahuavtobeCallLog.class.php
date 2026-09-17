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
 * Lecture du journal d'appels du portier.
 *
 * Ce fichier ne charge PAS le coeur de Jeedom, et c'est délibéré : découper une
 * réponse et convertir un horodatage ne demandent rien à Jeedom. Isolée, cette
 * logique se rejoue hors ligne sur des réponses réelles du portier — c'est tout
 * l'objet de tests/run.php, qui n'a besoin ni d'une base de données, ni d'un
 * portier joignable, ni même de Jeedom.
 */

class dahuavtobeCallLog {

    /*
     * Le portier tient un journal de ses appels, et c'est la seule mémoire de ce
     * qui s'est passé pendant que Jeedom n'écoutait pas — mise à jour du plugin,
     * redémarrage, coupure réseau, ou tout simplement un flux d'événements qui
     * n'a jamais porté la sonnerie sur ce modèle.
     *
     * Ce que ce micrologiciel ne sait PAS faire, et qui dicte toute la méthode :
     * pas de pagination, pas de tri inverse, pas de filtre par date. Les trois
     * ont été essayés sous une trentaine d'orthographes ; un paramètre inconnu
     * est avalé en silence et la réponse est identique. On télécharge donc le
     * journal entier — une centaine de kilo-octets, moins d'une seconde — et on
     * trie ici.
     */
    const LOG_NAME = 'VideoTalkLog';

    /* Au-delà, on ne remonte pas : un repère perdu ne doit pas faire rejouer
     * trois ans de sonneries. */
    const MAX_AGE = 172800;          // 48 heures

    /*
     * Découpe la réponse de recordFinder.cgi.
     *
     * Format : « found=N » puis une ligne par champ, « records[i].Champ=valeur »,
     * en CRLF. La valeur peut être vide, et le signe égal peut réapparaître
     * dedans : on ne coupe qu'au PREMIER.
     */
    public static function parse($_raw) {
        $records = array();
        foreach (preg_split('/\r\n|\n|\r/', (string) $_raw) as $line) {
            if (!preg_match('/^records\[(\d+)\]\.(\w+)=(.*)$/', $line, $m)) {
                continue;
            }
            $records[(int) $m[1]][$m[2]] = $m[3];
        }
        ksort($records);
        return array_values($records);
    }

    /*
     * Convertit le CreateTime du portier en horodatage utilisable.
     *
     * Le portier inscrit son heure MURALE dans un champ qui a toutes les
     * apparences d'un epoch. Lu tel quel, il annonce des appels deux heures dans
     * le futur — ce qui a été constaté : trois enregistrements du journal
     * d'accès étaient datés après l'heure courante.
     *
     * La correction ne code aucun décalage en dur. On relit l'heure murale avec
     * gmdate — qui rend exactement ce que le portier a voulu écrire — puis on
     * l'interprète dans le fuseau de Jeedom. Le jour où le portier change de
     * fuseau, ou passe à l'heure d'été, il n'y a rien à retoucher.
     *
     * Reste que tous les modèles n'encodent pas pareil : des relevés publics
     * montrent des portiers dont l'epoch est un vrai temps universel, et
     * d'autres décalés de l'offset local comme celui-ci. Aucune requête ne
     * permet de trancher a priori — il faudrait un enregistrement créé à
     * l'instant même pour comparer.
     *
     * On retient donc l'heure murale, et ce n'est pas un pile ou face : les deux
     * erreurs possibles n'ont pas le même coût. Lire un portier « heure murale »
     * comme de l'UTC place ses appels deux heures dans le FUTUR, et le
     * rattrapage les écarte en silence — des sonneries disparaîtraient sans que
     * rien ne le signale. L'erreur inverse les place deux heures dans le passé :
     * ils restent dans la fenêtre, sont rattrapés, et seule la date affichée est
     * fausse. Entre perdre un visiteur et l'afficher avec deux heures d'avance,
     * le choix est fait.
     */
    public static function time($_createTime) {
        $createTime = (int) $_createTime;
        if ($createTime <= 0) {
            return null;
        }
        $timestamp = strtotime(gmdate('Y-m-d H:i:s', $createTime));
        return ($timestamp === false) ? null : $timestamp;
    }

    /*
     * Normalise un enregistrement brut en un appel exploitable, ou null.
     *
     * Un appui sur le bouton se présente comme un appel SORTANT du portier vers
     * le moniteur intérieur : c'est le portier qui appelle, pas le visiteur qui
     * est appelé. Tout ce qui n'a pas cette forme n'est pas une sonnerie.
     */
    public static function normalize($_record) {
        if (!isset($_record['CreateTime'])) {
            return null;
        }
        $timestamp = self::time($_record['CreateTime']);
        if ($timestamp === null) {
            return null;
        }
        if (isset($_record['CallType']) && $_record['CallType'] != '' && $_record['CallType'] != 'Outgoing') {
            return null;
        }
        /* Deux signes concordants pour « personne n'a décroché ». EndState est
         * le champ prévu pour ça, TalkTime le confirme : une conversation qui a
         * eu lieu a forcément duré. */
        $endState = isset($_record['EndState']) ? $_record['EndState'] : '';
        $talkTime = isset($_record['TalkTime']) ? (int) $_record['TalkTime'] : 0;
        return array(
            'time'   => $timestamp,
            'missed' => ($endState == 'Missed' || $talkTime === 0),
            'peer'   => isset($_record['PeerNumber']) ? $_record['PeerNumber'] : '',
        );
    }

    /*
     * Découpe le journal en trois : ce qu'on connaît déjà, ce qui est trop vieux
     * pour être annoncé, et ce qu'il faut signaler.
     *
     * La garde d'âge n'est pas une coquetterie. Le repère vit dans la
     * configuration de l'équipement : le jour où il est perdu — équipement
     * dupliqué, restauration d'une sauvegarde ancienne — sans elle, le plugin
     * annoncerait d'un coup trois années de sonneries, réveillerait tous les
     * scénarios branchés dessus et remplirait l'historique. Passé ce délai, un
     * appel n'est plus une nouvelle : c'est de l'archive.
     */
    public static function since($_calls, $_mark, $_now, $_maxAge = self::MAX_AGE) {
        $nouveaux = array();
        foreach ($_calls as $call) {
            if (!isset($call['time'])) {
                continue;
            }
            if ($_mark > 0 && $call['time'] <= $_mark) {
                continue;                     // déjà connu
            }
            if (($_now - $call['time']) > $_maxAge) {
                continue;                     // de l'archive, pas une nouvelle
            }
            /* Un appel daté du futur vient d'une horloge déréglée, pas d'un
             * visiteur en avance. On ne le compte pas. */
            if ($call['time'] > $_now + 300) {
                continue;
            }
            $nouveaux[] = $call;
        }
        return $nouveaux;
    }

    /* Sonneries restées sans réponse sur la fenêtre donnée. Recalculé à chaque
     * passage depuis le journal complet : il n'y a rien à incrémenter, donc rien
     * à compter deux fois. */
    public static function countMissed($_calls, $_now, $_window = 86400) {
        $total = 0;
        foreach ($_calls as $call) {
            if (empty($call['missed']) || !isset($call['time'])) {
                continue;
            }
            if (($_now - $call['time']) <= $_window && $call['time'] <= $_now + 300) {
                $total++;
            }
        }
        return $total;
    }
}
