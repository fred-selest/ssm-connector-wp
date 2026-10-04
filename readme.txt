=== SSM Connector ===
Contributors: selest-informatique
Tags: monitoring, maintenance, inventory
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: Private

Connecteur SSM (Selest Site Manager) : envoie toutes les heures l'inventaire du site à SSM Core. N'ouvre aucune porte sur le site.

== Description ==

Extension privée réservée aux clients Selest Informatique.

Elle envoie à SSM Core, toutes les heures :
* la version de WordPress, de PHP et de la base de données, le serveur web, le nom de la machine ;
* les extensions et les thèmes, avec les mises à jour disponibles.

Rien d'autre : ni utilisateurs, ni contenu, ni e-mails. L'extension n'expose aucune route et n'écoute rien ; elle ne modifie rien sur le site.

Sécurité : token chiffré dans la base (ou défini dans wp-config.php, hors base), https obligatoire hors réseau privé, certificat vérifié.

Mises à jour : depuis les releases GitHub, comme n'importe quelle extension (Extensions, Tableau de bord, wp plugin update).

== Installation ==

1. Téléversez `ssm-connector-wp.zip` (Extensions > Ajouter > Téléverser) puis activez l'extension.
2. Dans SSM Core (Sites, bouton 🔌), copiez le token du site.
3. Réglages > SSM Connector : saisissez l'adresse de SSM, collez le token, cliquez sur Connecter.
4. Le résultat s'affiche aussitôt (✔ Connecté, ou la cause de l'échec).

En ligne de commande : `echo "$TOKEN" | wp ssm connect https://ssm.exemple.fr`.

== Screenshots ==

1. Avant la connexion : le guide en trois étapes.
2. Connecté : état, dernier envoi, sécurité.
3. Un échec expliqué : token refusé, avec la marche à suivre.
4. Rappel sur la liste des extensions tant que le site n'est pas connecté.
5. Une mise à jour disponible, avec le logo.
6. Configuration fixée dans wp-config.php : champs verrouillés, token hors base de données.

== Changelog ==

= 0.3.0 =
* Configuration en un formulaire et un bouton « Connecter » : enregistre, chiffre le token et teste aussitôt, avec un résultat clair.
* Token chiffré dans la base, ou défini dans wp-config.php (SSM_CONNECTOR_URL, SSM_CONNECTOR_TOKEN) : jamais en clair.
* https obligatoire hors réseau privé ; plus aucune route REST ; plus de file d'événements ni de données personnelles envoyées.
* Mises à jour depuis les releases GitHub, intégrées à WordPress (somme SHA-256 contrôlée).
* Logo, rappel tant que le site n'est pas connecté, lien « Réglages », commande `wp ssm connect`.

= 0.2.1 =
* Correction : erreur fatale au chargement (méthode deactivate() déclarée deux fois) : l'extension ne pouvait pas être activée.
* Correction : les extensions n'étaient pas reçues par SSM Core (clé « plugins » au lieu de « extensions »), qui vidait alors la liste à chaque envoi.
* Correction : le token de SSM se colle maintenant dans l'extension (champ dédié) ; l'URL de SSM Core s'enregistre enfin (réglage jamais déclaré auparavant).
* Correction : valeurs tronquées aux limites de SSM Core (version de PHP, etc.) pour éviter un refus 422 de tout l'inventaire.
* Correction : la désactivation d'une extension n'était pas distinguée de son activation.
* Correction : les événements ne sont plus perdus quand l'envoi échoue ; les redirections ne sont plus suivies.
* Ajout : bouton « Envoyer un heartbeat maintenant », état du dernier envoi avec la cause de l'échec, commande `wp ssm heartbeat`.
* Ajout : nom de la machine, chemin du site, version de la base ; mises à jour de thèmes ; token créé aussi en mu-plugin ; uninstall.php.
* Le token n'est plus accepté dans l'URL des routes REST et n'est plus affiché en entier.

= 0.2.0 =
* Page de réglages, envoi horaire, file d'événements, signature HMAC. Ne se chargeait pas (voir 0.2.1).

= 0.1.0 =
* Version initiale.
