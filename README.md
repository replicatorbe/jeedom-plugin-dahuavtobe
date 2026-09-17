# Plugin Jeedom — Dahua VTO

Connexion directe à un portier vidéo Dahua VTO, sans enregistreur intermédiaire.
Le plugin ouvre une écoute permanente sur le portier et remonte dans Jeedom les
appels de la sonnette, les fins d'appel sans réponse, les ouvertures de gâche et
les alarmes de l'appareil. À chaque sonnerie, il photographie le visiteur.

Testé sur un **DHI-VTO2211G-WP**, micrologiciel 4.511.0000000.0.R.

## Ce que le plugin expose

Cinq commandes sont visibles sur le dashboard, les autres existent et sont
alimentées mais restent masquées : seize tuiles pour une sonnette seraient
illisibles. Une case à cocher suffit à en afficher une.

| Commande | Type | Rôle | Visible |
|---|---|---|---|
| `sonnerie` | info / binaire | Passe à 1 quand quelqu'un sonne, et retombe seule | oui |
| `etat_appel` | info / texte | Repos, Sonne, En conversation, Appel manqué | oui |
| `porte` | info / binaire | État de la gâche | oui |
| `snapshot` | info / texte | La photo du visiteur, affichée sur le dashboard | oui |
| `en_ligne` | info / binaire | Liaison d'événements établie | oui |
| `dernier_appel` | info / texte | Horodatage du dernier appel | non |
| `appel_manque` | info / binaire | Appel terminé sans réponse | non |
| `appels_manques_24h` | info / numérique | Sonneries sans réponse des dernières 24 h | oui |
| `dernier_acces` | info / texte | Qui a ouvert, et par quel moyen | non |
| `porte_non_fermee` | info / binaire | Porte restée ouverte | non |
| `sabotage` | info / binaire | Alarme locale du portier | non |
| `dernier_evenement` | info / texte | Le dernier code reçu, brut | non |
| `capture` | action | Prendre une photo maintenant | oui |
| `reconnecter` | action | Forcer une reconnexion | non |
| `ouvrir` | action | Ouvrir la gâche — désactivée par défaut | non |

## Le rattrapage des sonneries manquées

Le portier tient lui-même le journal de ses appels. Le plugin le relit
périodiquement et retrouve les sonneries survenues pendant qu'il n'écoutait
pas : mise à jour, redémarrage de Jeedom, coupure réseau. C'est aussi le filet
de sécurité si le flux d'événements d'un modèle ne portait jamais la sonnerie.

Deux choses qu'il ne fait pas, et c'est délibéré. Il **n'actionne pas** la
commande `sonnerie` : elle déclenche des scénarios, et rejouer à minuit la
sonnerie de l'après-midi allumerait la maison pour un visiteur reparti depuis
longtemps. Il **ne remonte pas plus de 48 heures** : au-delà, un appel n'est
plus une nouvelle, c'est de l'archive — et le jour où le repère est perdu, cette
garde évite d'annoncer d'un coup trois années de sonneries.

Ce rattrapage n'est pas un chemin temps réel. Le portier n'écrit son
enregistrement qu'à la **fin** de l'appel, et le plugin relit le journal à
intervalle réglable : comptez ce délai plus la durée de sonnerie avant qu'un
appel rattrapé n'apparaisse. Pour réagir dans la seconde, c'est la commande
`sonnerie`, alimentée par le flux d'événements, qui fait foi.

## Deux précautions

**L'ouverture de la porte est verrouillée à l'installation.** La commande existe
mais elle est créée invisible et refuse de s'exécuter tant que la case
correspondante n'est pas cochée dans la configuration du plugin. Ouvrir une
porte d'entrée ne doit pas être à portée d'un clic involontaire.

**Le mot de passe du portier est conservé en clair** dans la base de Jeedom.
L'authentification Digest exige de le présenter à chaque requête : il ne peut pas
être remplacé par une empreinte. Utilisez un compte dédié sur le portier si vous
le pouvez.

Le plugin n'expose **aucune adresse de flux vidéo** : il faudrait y écrire les
identifiants pour qu'elle soit utilisable, et la stocker dans une commande
reviendrait à les afficher. Les photos suffisent, et elles ne sont servies
qu'aux utilisateurs ayant un droit de lecture sur l'équipement : connaître
l'adresse d'une image ne suffit pas à l'obtenir.

## Licence

AGPL v3.
