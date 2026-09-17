# Plugin Dahua VTO

Ce plugin relie Jeedom à un portier vidéo Dahua **VTO**, directement, sur votre
réseau local. Aucun enregistreur, aucun cloud, aucun compte constructeur.

Quand un visiteur sonne, Jeedom le sait dans la seconde et garde une photo de
lui. Vous pouvez alors déclencher ce que vous voulez : une notification sur le
téléphone, une lumière qui s'allume, un message sur une enceinte.

## Avant de commencer

Il vous faut trois choses :

1. **L'adresse IP du portier.** Vous la trouverez dans l'interface de votre box,
   ou dans l'application Dahua. Réservez-la dans votre routeur : si elle change,
   le plugin ne trouvera plus rien et ne saura pas dire pourquoi.
2. **Un compte sur le portier.** Celui que vous utilisez pour ouvrir son
   interface web. Le compte `admin` convient ; un compte dédié est préférable.
3. **Que le portier soit joignable depuis Jeedom.** Si les deux sont sur des
   réseaux séparés, ouvrez les ports 80 et 5000 entre eux.

Pour vérifier que tout est en place avant même d'installer le plugin, depuis une
console sur la machine Jeedom :

```bash
curl --digest -u 'admin:votre_mot_de_passe' \
  'http://192.168.1.50/cgi-bin/magicBox.cgi?action=getDeviceType'
```

Le portier doit répondre `type=VTO2211G-WP` ou l'équivalent pour votre modèle.

## Installation

1. Installez le plugin, puis activez-le.
2. Ouvrez sa configuration (la roue dentée) et parcourez les réglages. Les
   valeurs par défaut conviennent : n'y touchez que si vous savez pourquoi.
3. Revenez sur la page du plugin et cliquez sur **Ajouter un portier**.
4. Donnez-lui un nom, choisissez l'objet parent — sans objet parent, l'équipement
   n'apparaîtra sur aucun dashboard — puis saisissez l'adresse, l'identifiant et
   le mot de passe.
5. Cliquez sur **Tester la connexion**. Le plugin affiche le modèle et la version
   du portier : c'est la preuve que l'adresse et les identifiants sont bons.
6. **Sauvegardez.** Les commandes sont créées, et le démon se met à écouter.

## Ce que vous voyez ensuite

L'onglet **Diagnostic** de l'équipement affiche les événements tels que le
portier les envoie, avant toute interprétation. C'est la page à garder ouverte la
première fois : les codes varient d'un modèle et d'un micrologiciel à l'autre, et
c'est là que vous verrez ce qui arrive réellement quand quelqu'un appuie sur le
bouton.

Sur le dashboard, la commande **Sonnerie** passe à 1 à chaque appel, puis
retombe seule après quelques secondes — c'est la durée réglable dans la
configuration du plugin. Le portier annonce l'appui sur le bouton, jamais sa
fin : sans cette retombée, la commande resterait à 1 et aucun scénario ne se
redéclencherait à la visite suivante.

C'est donc **Sonnerie** qu'on utilise comme déclencheur, et non **État de
l'appel**, dont le texte change avec la langue de l'interface.

La commande **Image** affiche directement la photo du visiteur sur le
dashboard, avec l'heure de la prise de vue ; un clic l'ouvre en grand.

Cinq commandes seulement sont visibles au départ. Les autres — dernier appel,
appel manqué, dernier accès, porte restée ouverte — existent et sont tenues à
jour : elles sont simplement masquées pour ne pas noyer le dashboard. Une case à
cocher dans l'onglet Commandes suffit à en afficher une.

## Un scénario de bienvenue

Déclencheur : la commande **Sonnerie** passe à 1.

```
SI [Portier][Sonnerie] == 1 ALORS
    notification(Quelqu'un sonne à la porte)
FIN
```

Pour joindre la photo du visiteur à une notification, utilisez la commande
**Image** : elle contient l'adresse de la dernière capture. Attention, cette adresse est protégée : il faut
être connecté à Jeedom **et** avoir un droit de lecture sur le portier. Elle
s'affiche donc dans l'interface, mais un service extérieur (Telegram, un
courriel) ne pourra pas la charger seul.

Une photo est également prise à chaque ouverture de la porte, badge ou code
compris : ces ouvertures-là ne font pas sonner le portier, et sans elle rien ne
dirait qui vient d'entrer. Le réglage se désactive dans la configuration du
plugin.

Si la capture échoue — portier occupé, réseau lent — la commande **Image** garde
la photo de la visite précédente, faute de mieux. Le plugin l'écrit alors au
journal : c'est la seule façon de savoir qu'un visage affiché n'est pas celui du
visiteur qui attend.

## Ouvrir la porte depuis Jeedom

La commande **Ouvrir la porte** existe, mais elle est délibérément bridée :

- elle est créée **invisible** sur le dashboard ;
- elle **refuse de s'exécuter** tant que la case « Autoriser l'ouverture » n'est
  pas cochée dans la configuration du plugin.

Ce double verrou est volontaire. Une commande d'action Jeedom s'exécute aussi
bien depuis un scénario que depuis un clic, et la porte d'entrée n'est pas un
interrupteur de lampe. Quand vous l'activez, les ouvertures venant de Jeedom sont
inscrites dans le journal d'accès du portier, avec l'identifiant utilisateur
indiqué dans la configuration : vous pourrez les distinguer d'un badge ou d'un
code.

## En cas de problème

**« Le portier est injoignable. »** L'adresse a changé, ou le réseau ne passe
pas. Vérifiez avec la commande `curl` plus haut.

**« Le portier a refusé les identifiants. »** Le compte ou le mot de passe est
faux. Attention aux mots de passe qui se terminent par un point ou un caractère
de ponctuation : ils font partie du mot de passe.

**Le démon ne démarre pas.** Regardez le journal `dahuavtobed` (Analyse →
Journaux). Il dit ce que le portier a répondu.

**Rien ne remonte quand on sonne.** Ouvrez l'onglet Diagnostic et faites sonner.
Si des événements y apparaissent mais que la commande Sonnerie ne bouge pas, le
code employé par votre modèle n'est pas encore reconnu : ouvrez un ticket en
joignant ces lignes, c'est exactement ce qu'il faut pour l'ajouter.

**L'heure des appels est décalée.** L'horloge du portier est souvent réglée en
UTC. Le plugin réhorodate les événements à leur arrivée, mais il est plus propre
de régler l'heure et le fuseau dans l'interface du portier.

## Ce que le plugin ne fait pas

- Il n'affiche pas de vidéo en direct : il montre la photo prise à la sonnerie.
  Le flux RTSP du portier reste accessible avec un lecteur comme VLC, qui vous
  demandera les identifiants — le plugin ne les stocke nulle part ailleurs que
  dans la configuration de l'équipement.
- Il ne décroche pas et ne parle pas au visiteur : cela reste le rôle du moniteur
  intérieur (VTH) ou de l'application.
- Il n'est pas affilié à Dahua.
