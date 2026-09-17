# Fixtures

Réponses réelles d'un portier **DHI-VTO2211G-WP**, micrologiciel
`4.511.0000000.0.R`, capturées telles quelles — fins de ligne CRLF comprises.
Elles servent à rejouer le découpage et la conversion des horodatages hors ligne,
sans portier joignable ni base de données.

## Une seule chose a été modifiée

Les `CreateTime` de `videotalklog-full.txt` ont été **décalés d'une constante**.

Le journal d'appels d'un portier est l'historique des visites reçues à un
domicile : trois années d'horaires précis auxquels quelqu'un a sonné à la porte,
dans un dépôt public signé d'un nom. Les intervalles entre appels sont conservés
— c'est ce qui donne au jeu d'essai sa valeur — mais ni les dates ni les heures
ne correspondent plus à des visites réelles.

Tout le reste est intact : nombre d'enregistrements, ordre, noms et ordre des
champs, valeurs vides, fins de ligne. C'est la forme qu'on veut figer ici, et
elle n'a pas bougé.
