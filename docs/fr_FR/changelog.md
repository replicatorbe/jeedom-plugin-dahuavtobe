# Changelog

## 0.1

Première version.

- Connexion directe à un portier Dahua VTO, sans enregistreur.
- Test de connexion affichant le modèle, la version et l'heure du portier.
- Capture d'image à la demande, conservée en rotation et servie après contrôle
  de session.
- Commandes de la sonnette, de l'appel, de la porte et de l'état de l'appareil.
- Ouverture de la gâche, verrouillée par défaut et créée invisible.
- Les images ne sont servies qu'aux utilisateurs ayant un droit de lecture sur
  le portier.
- La durée pendant laquelle la commande Sonnerie reste à 1 est réglable.
- La photo du visiteur s'affiche directement sur le dashboard, avec l'heure de
  la prise de vue.
- La commande d'adresse RTSP est retirée : elle n'aurait été utilisable qu'en y
  inscrivant le mot de passe du portier.
- Les réglages du plugin sont pris en compte sans redémarrer le démon.
- La page Santé distingue le démon arrêté d'un portier injoignable.
- L'état de la porte est relevé sur le portier au démarrage, au lieu d'être
  supposé fermé.
- Une photo est prise à l'ouverture de la porte, pas seulement à la sonnerie.
