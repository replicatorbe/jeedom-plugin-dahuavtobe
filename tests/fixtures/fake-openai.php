<?php
/* Faux service compatible OpenAI, pour le rejeu hors ligne de l'analyse des
 * visiteurs. Lancé par tests/run.php avec le serveur intégré de PHP :
 *
 *   php -S 127.0.0.1:<port> tests/fixtures/fake-openai.php
 *
 * Le nom du modèle demandé choisit le comportement : c'est le seul champ de la
 * charge que le client laisse passer tel quel. */

$charge = json_decode((string) file_get_contents('php://input'), true);
$modele = isset($charge['model']) ? $charge['model'] : '';
/* Ce que le client a envoyé, pour que le test puisse le vérifier. */
file_put_contents(sys_get_temp_dir() . '/dahuavtobe-fake-derniere.json', json_encode($charge));

function repondre($_code, $_corps) {
    http_response_code($_code);
    header('Content-Type: application/json');
    echo is_string($_corps) ? $_corps : json_encode($_corps);
    exit;
}
function contenu($_texte, $_modele) {
    return array('model' => $_modele, 'choices' => array(array('message' => array('role' => 'assistant', 'content' => $_texte))));
}
$livreur = json_encode(array('categorie' => 'livreur', 'confiance' => 0.93, 'description' => 'Une personne en gilet jaune tient un colis.',
                             'indices' => array('gilet jaune', 'colis'), 'meilleure_image' => 2));

switch ($modele) {
    case 'ok':
        repondre(200, contenu($livreur, 'ok-2026'));
    case 'refuse-max-tokens':
        if (isset($charge['max_tokens'])) {
            repondre(400, array('error' => array('message' => "Unsupported parameter: 'max_tokens' is not supported with this model. Use 'max_completion_tokens' instead.")));
        }
        repondre(200, contenu($livreur, $modele));
    case 'sans-schema':
        if (isset($charge['response_format']['type']) && $charge['response_format']['type'] === 'json_schema') {
            repondre(400, array('error' => array('message' => "Invalid parameter: 'response_format' of type 'json_schema' is not supported.")));
        }
        repondre(200, contenu("```json\n" . $livreur . "\n```", $modele));
    case 'panne-puis-ok':
        $drapeau = sys_get_temp_dir() . '/dahuavtobe-fake-panne';
        if (!file_exists($drapeau)) {
            touch($drapeau);
            repondre(503, array('error' => array('message' => 'overloaded')));
        }
        unlink($drapeau);
        repondre(200, contenu($livreur, $modele));
    case 'cle-refusee':
        repondre(401, array('error' => array('message' => 'Incorrect API key provided')));
    case 'lent':
        sleep(7);
        repondre(200, contenu($livreur, $modele));
    case 'hors-format':
        repondre(200, contenu('Je pense que c\'est un livreur.', $modele));
    case 'categorie-inconnue':
        repondre(200, contenu(json_encode(array('categorie' => 'voleur', 'confiance' => 0.9, 'description' => '',
                                                'indices' => array(), 'meilleure_image' => 1)), $modele));
    case 'pourcent':
        repondre(200, contenu(json_encode(array('categorie' => 'demarcheur', 'confiance' => 85, 'description' => 'Tablette en main.',
                                                'indices' => array('tablette'), 'meilleure_image' => 9)), $modele));
    case 'refus':
        repondre(200, array('model' => $modele, 'choices' => array(array('message' => array('role' => 'assistant', 'content' => null,
                                                                                               'refusal' => 'Je ne peux pas aider.')))));
}
repondre(404, array('error' => array('message' => 'The model `' . $modele . '` does not exist')));
