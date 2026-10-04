=== SSM Connector ===
Contributors: selest-informatique
Tags: monitoring, maintenance, inventory
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: Private

Connecteur SSM (Selest Site Manager) : envoie toutes les heures l'inventaire du site à SSM Core.

== Description ==

Extension privée réservée aux clients Selest Informatique.

Elle envoie à SSM Core, toutes les heures :
* la version de WordPress, de PHP et de la base de données ;
* les extensions et les thèmes, avec les mises à jour disponibles ;
* en file d'attente, les connexions, les (dés)activations d'extensions et les mises à jour (SSM Core ne les exploite pas encore).

Elle ne modifie rien sur le site.

== Installation ==

1. Téléversez `ssm-connector-wp.zip` (Extensions > Ajouter > Téléverser) puis activez l'extension.
2. Dans SSM Core (Sites, bouton 🔌), copiez le token du site.
3. Réglages > SSM Connector : saisissez l'URL de SSM Core et collez le token, puis Enregistrer.
4. Cliquez sur « Envoyer un heartbeat maintenant » : le résultat s'affiche dans la section État.

== Changelog ==

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
