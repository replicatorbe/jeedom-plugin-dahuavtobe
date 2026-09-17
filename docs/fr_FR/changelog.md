# Changelog

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
