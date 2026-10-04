# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions sémantiques.

## [Unreleased]

## [0.2.1] - 2026-10-04

Version de correction : la 0.2.0 ne pouvait pas être activée.

### Corrigé
- **Erreur fatale au chargement** : `deactivate()` était déclarée deux fois. WordPress refusait d'activer l'extension (et en mu-plugin, tout le site aurait été en erreur).
- **Extensions jamais reçues** : l'inventaire utilisait la clé `plugins`, SSM Core lit `extensions`. Core recevait donc une liste vide et effaçait les extensions du site à chaque envoi.
- **Jumelage impossible** : le guide de SSM demande de coller son token dans l'extension, qui n'avait aucun champ pour cela. Il y en a un maintenant (champ vide = token conservé ; seuls les 4 derniers caractères s'affichent).
- **URL de SSM Core non enregistrable** : le réglage n'était pas déclaré (`register_setting`), WordPress refusait l'enregistrement.
- **Inventaire refusé en bloc (422)** : les valeurs sont tronquées aux limites de SSM Core, la version de PHP est envoyée sous la forme `X.Y.Z` (une version Debian dépassait 20 caractères), les doublons d'identifiants sont évités.
- La désactivation d'une extension était enregistrée comme une activation.
- Mises à jour de plugins et de thèmes jamais vues par les routes REST (mauvaise source de données) ; version proposée pour un thème jamais envoyée à SSM Core.
- Événements supprimés avant l'envoi (perdus en cas d'échec) ; l'URL vide n'était pas détectée ; redirections suivies avec le token.
- Statut du dernier envoi toujours « Pas de ping » dans l'administration.
- Compatibilité annoncée PHP 7.4 / WordPress 5.5 : `str_contains` (PHP 8 ou WordPress 5.9) n'est plus utilisé.

### Ajouté
- Bouton **Envoyer un heartbeat maintenant**, état du dernier envoi avec la cause de l'échec (token refusé, URL introuvable, champ refusé…), commande `wp ssm heartbeat`.
- Nom de la machine, chemin d'installation et version de la base dans l'inventaire.
- Création du token aussi en mu-plugin (le hook d'activation n'y existe pas) ; protection contre un double chargement.
- `uninstall.php` : effacement des réglages à la suppression.
- Tests sans WordPress (`tests/`), contrôle de cohérence des versions, workflows CI et Release (ZIP `ssm-connector-wp.zip` à nom stable, comme le guide de SSM l'attend).

### Modifié
- Le token n'est plus accepté dans l'URL des routes REST (seulement `X-SSM-Token` ou `Authorization: Bearer`) et n'est plus affiché en entier.
- Un plugin d'un seul fichier a pour identifiant le nom du fichier sans `.php` (et non son nom affiché, qui peut être traduit).
- README et `readme.txt` réécrits : ils décrivaient des routes, un hachage et une liste d'adresses autorisées qui n'ont jamais existé.

## [0.2.0] - 2026-09-30
- Page de réglages, envoi horaire, file d'événements, signature HMAC. Ne se chargeait pas.

## [0.1.0] - 2026-09-29
- Version initiale.
