# Changelog

## 0.2

**Qui a sonné ?**

- À chaque sonnerie, le plugin peut faire analyser les photos du visiteur par un
  modèle de vision compatible OpenAI (OpenAI, ou un modèle local comme Ollama).
  La commande **Visiteur** reçoit la réponse : `livreur`, `demarcheur`,
  `professionnel`, `visiteur`, `vide` (personne dans le cadre) ou
  `indetermine`.
- Une rafale de photos, jusqu'à quatre à deux secondes d'intervalle : la
  personne qui sonne se tient souvent tout contre le portier, hors du cadre, et
  c'est en reculant qu'on voit son colis ou sa tablette.
- Des actions par catégorie, sur l'équipement : on choisit pour quels visiteurs
  être prévenu. Elles partent en arrière-plan, avec des tags pour le message et
  la photo à joindre (`#libelle#`, `#description#`, `#image#`…).
- Sous le seuil de confiance, ou si le service ne répond pas, la visite est
  classée `indetermine` avec la raison : une sonnerie reçoit toujours une
  réponse.
- Une analyse d'image ne peut pas ouvrir la porte : les commandes du portier et
  d'ouverture de serrure sont refusées comme actions.
- Désactivée par défaut, et limitée aux sonneries : les ouvertures par badge ou
  par code ne sont jamais analysées.

## 0.1

Première version.

Elle se connecte directement à un portier Dahua VTO, sans enregistreur
intermédiaire, et n'a besoin ni de cloud ni de compte constructeur.

**Recevoir les visiteurs**

- La commande **Sonnerie** passe à 1 dès qu'on appuie sur le bouton, et retombe
  seule après quelques secondes — le portier annonce l'appui, jamais sa fin.
- L'état de l'appel suit son déroulement : sonne, décroché, raccroché, manqué.
- Une photo du visiteur est prise au moment même de la sonnerie, par le démon,
  avant qu'un scénario ait eu le temps de se réveiller. Elle s'affiche
  directement sur le dashboard, avec l'heure de la prise de vue.
- Le rattrapage relit le journal d'appels du portier et retrouve les sonneries
  survenues pendant que Jeedom n'écoutait pas — mise à jour, redémarrage,
  coupure réseau.

**La porte**

- L'état de la gâche est relevé sur le portier, jamais supposé.
- Une photo est également prise à l'ouverture : un badge ou un code ne font pas
  sonner, et sans elle rien ne dirait qui vient d'entrer.
- La commande d'ouverture est créée invisible et refuse de s'exécuter tant
  qu'elle n'a pas été autorisée dans la configuration du plugin.

**Sous le capot**

- Deux transports pour écouter les événements, le long-polling CGI et le
  protocole natif DHIP, avec bascule automatique dans les deux sens quand l'un
  d'eux refuse la connexion.
- Les images ne sont servies qu'aux utilisateurs ayant un droit de lecture sur
  l'équipement : connaître l'adresse d'une photo ne suffit pas à l'obtenir.
- Aucune adresse de flux vidéo n'est exposée : elle n'aurait été utilisable
  qu'en y inscrivant le mot de passe du portier.
- Les réglages du plugin sont pris en compte sans redémarrer le démon.

Testé sur un DHI-VTO2211G-WP, micrologiciel 4.511.0000000.0.R.
