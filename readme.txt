=== SSM Connector ===
Contributors: selest-informatique
Tags: monitoring, maintenance, security, inventory
Requires at least: 5.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: Private

Connecteur SSM (Selest Site Manager) — inventaire + MAJ + logs + sécurité.

== Description ==

Plugin privé réservé aux clients Selest Informatique.

Fonctionnalités :
* Inventaire automatique (plugins, thèmes, versions PHP/WP, multisite, users)
* Heartbeat horaire avec rapport détaillé vers SSM Core
* Event queue : login, plugin (de)activation, MAJ core/extension/thème
* Signature HMAC-SHA256 des requêtes
* Page d'admin pour configurer l'URL SSM Core et voir le statut heartbeat

== Installation ==

1. Téléchargez `ssm-connector-wp-v0.2.0.zip`
2. WP Admin > Extensions > Ajouter > Téléverser le ZIP
3. Activer le plugin
4. Réglages > SSM Connector : saisir l'URL SSM Core
5. Copier le token affiché et le coller dans l'admin SSM Core (interface Sites > Détail)

== Changelog ==

= 0.2.0 =
* Admin UI (Settings > SSM Connector)
* Heartbeat cron horaire avec inventaire complet
* Event queue (login, plugin, MAJ core/extensions)
* HMAC-SHA256 signature

= 0.1.0 =
* Release initiale (status, extensions, heartbeat)