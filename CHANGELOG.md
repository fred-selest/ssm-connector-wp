# Changelog

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions sémantiques.

## [Unreleased]

## [0.3.0] - 2026-10-04

Configuration en un geste, token protégé, plus aucune porte ouverte sur le site, mises à jour intégrées à WordPress.

### Ajouté
- **Un formulaire, un bouton** : adresse de SSM + token, puis **Connecter**. Les réglages sont enregistrés, le token chiffré et la connexion testée aussitôt ; le résultat s'affiche en clair (✔ Connecté, ou la cause de l'échec et quoi faire).
- L'adresse copiée depuis le navigateur est nettoyée (`https://ssm.exemple.fr/sites?x=1` → `https://ssm.exemple.fr`) ; le token collé aussi (espaces, retours à la ligne, guillemets, préfixes `Bearer` / `X-SSM-Token:`).
- État en tête de page : « Pas encore connecté » avec le guide en trois étapes, ✔ Connecté, ou ✘ Échec.
- Rappel sur le Tableau de bord et la liste des extensions tant que le site n'est pas connecté ; lien **Réglages** sous le nom de l'extension.
- `wp ssm connect <url>` (token sur l'entrée standard, donc hors de l'historique du shell) ; `wp ssm status` ne montre que les 4 derniers caractères du token.
- Constantes `SSM_CONNECTOR_URL` et `SSM_CONNECTOR_TOKEN` dans `wp-config.php` : le token n'est alors jamais écrit dans la base de données (déploiements en série, hébergeurs gérés).
- **Mises à jour depuis les releases GitHub**, branchées sur WordPress : écran Extensions, Tableau de bord › Mises à jour, `wp plugin update ssm-connector`, mises à jour automatiques (au choix de l'administrateur). Le paquet est contrôlé (adresse limitée aux releases du dépôt, somme SHA-256 fournie par GitHub) ; le dossier installé est conservé même s'il porte un autre nom. Bouton « Rechercher une mise à jour », `SSM_CONNECTOR_DISABLE_UPDATES` pour les désactiver. Le dépôt doit être public : aucun jeton n'est stocké sur les sites.
- **Logo** (`assets/` : SVG, PNG 128 et 256) sur la page de réglages et dans l'écran des mises à jour de WordPress.

### Sécurité
- **Token chiffré dans la base** (libsodium, clé dérivée des clés de sécurité de `wp-config.php`). Les tokens enregistrés en clair par la 0.2.x sont chiffrés automatiquement au premier chargement. Si les clés de sécurité changent, la page le dit et il suffit de recoller le token.
- **https obligatoire** hors réseau privé : une adresse `http://` vers Internet est refusée, le token n'est jamais envoyé en clair. Certificat toujours vérifié, redirections jamais suivies.
- **Plus aucune porte d'entrée** : les trois routes REST (`/wp-json/ssm/v1/…`) sont supprimées. SSM Core ne les appelait pas ; l'extension envoie, elle n'écoute rien.
- **Moins de données** : la file d'événements est supprimée (elle contenait les identifiants saisis à la connexion, y compris les échecs, que SSM Core ignorait). L'inventaire ne contient plus l'e-mail de l'administrateur, le nombre d'utilisateurs ni les adresses du site. La signature HMAC (jamais vérifiée) est retirée.
- Le token n'est jamais affiché en entier, ni dans un message d'erreur ; les réglages et les boutons exigent `manage_options` et un jeton anti-CSRF.

### Retiré
- Les routes REST du site, la file d'événements, la commande `wp ssm token`, la création d'un token aléatoire à l'activation (il n'était connu que de WordPress : SSM répondait 401 sans qu'on sache pourquoi).

### Corrigé
- Le numéro de version d'une release doit être strictement `X.Y.Z` (PHP accepte un saut de ligne final avec `$`).
- Après une mise à jour, la version tout juste installée n'est plus proposée pendant 12 h (le code en mémoire est encore l'ancien pendant l'opération ; la version est lue sur le disque).

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
