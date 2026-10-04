# SSM Connector — WordPress

Extension WordPress qui envoie à **SSM Core** (Selest Site Manager) l'inventaire du site : version de WordPress, de PHP et de la base,
extensions et thèmes (avec les mises à jour disponibles). L'envoi est automatique, toutes les heures (WP-Cron).

Elle ne modifie rien sur le site : elle ne fait que lire et envoyer.

## Installation

1. Télécharger `ssm-connector-wp.zip` depuis la [dernière release](https://github.com/fred-selest/ssm-connector-wp/releases/latest).
2. WordPress : **Extensions › Ajouter › Téléverser**, puis **Activer**.
   En ligne de commande : `wp plugin install ssm-connector-wp.zip --activate`.

Variante *mu-plugin* : copier **le fichier** `ssm-connector.php` directement dans `wp-content/mu-plugins/` (pas dans un sous-dossier :
WordPress n'y charge que les fichiers du premier niveau). Il n'y a alors rien à activer. Ne pas installer les deux variantes à la fois.

## Configuration

1. Dans SSM : **Sites › 🔌** sur le site, copier le **token** (il n'est affiché qu'une fois ; un nouveau token se génère au même endroit).
2. Dans WordPress : **Réglages › SSM Connector**.
   - **URL de SSM Core** : par exemple `https://ssm.exemple.fr`.
   - **Token du site** : coller le token copié. Le champ reste vide ensuite (seuls les 4 derniers caractères s'affichent) ; le laisser vide conserve le token actuel.
3. **Envoyer un heartbeat maintenant** : le résultat s'affiche dans la section *État* (« accepté », ou la cause de l'échec : token refusé, URL introuvable, données refusées…).

Tant qu'aucun token n'est collé, le connecteur en utilise un aléatoire que SSM ne connaît pas : SSM répond 401.

En ligne de commande (même effet) :

```
wp option update ssm_connector_url https://ssm.exemple.fr
wp option update ssm_connector_token <token>
wp ssm heartbeat      # envoie tout de suite et affiche le résultat
wp ssm status         # configuration et dernier envoi
```

## Ce qui est envoyé

À chaque heartbeat, en HTTPS vers `<URL>/api/v1/heartbeat`, avec l'en-tête `X-SSM-Token` :

| Donnée | Lue par SSM Core |
|---|---|
| version de WordPress, de PHP, de la base, serveur web, nom de la machine, chemin d'installation | oui |
| extensions : identifiant, nom, version, active ou non, mise à jour disponible et version proposée | oui |
| thèmes : mêmes champs, plus le thème parent | oui |
| adresse du site, e-mail de l'administrateur, nombre d'utilisateurs, mode debug, multisite | non (ignoré pour l'instant) |
| événements en file : connexions réussies et échouées (identifiant saisi), activation/désactivation d'extensions, mises à jour | non (ignorés pour l'instant) |

Les événements sont conservés (100 au maximum) jusqu'à ce que SSM Core accepte un envoi. Une signature HMAC-SHA256 du corps est jointe
(`X-SSM-Signature`) ; SSM Core ne la vérifie pas encore.

## API REST du site

Trois routes en lecture, protégées par le token du site (`X-SSM-Token` ou `Authorization: Bearer`). Le token n'est jamais accepté dans l'URL.

```
GET      /wp-json/ssm/v1/status        # version du connecteur, de WordPress et de PHP
GET|POST /wp-json/ssm/v1/heartbeat     # état du site
GET      /wp-json/ssm/v1/extensions    # extensions et thèmes
```

SSM Core n'appelle pas ces routes : c'est le connecteur qui envoie.

## Sécurité

- Le token est stocké **en clair** dans `wp_options` (il faut le réexpédier à chaque envoi) ; il n'est jamais réaffiché en entier.
- Les redirections ne sont pas suivies lors de l'envoi, pour ne pas transmettre le token à une autre adresse. Saisir l'adresse finale de SSM Core (`https://`).
- Les réglages et le bouton d'envoi exigent la capacité `manage_options` et un jeton anti-CSRF (nonce).
- À la suppression de l'extension (pas à la simple désactivation), ses réglages sont effacés (`uninstall.php`).

## Compatibilité

WordPress 5.5 ou plus, PHP 7.4 ou plus. Multisite : l'inventaire est celui du site sur lequel l'extension est active.

## Développement

```
php tests/run.php                 # tests sans WordPress (faux appels WP)
sh scripts/check-version.sh       # la version est-elle la même partout ?
sh scripts/build-zip.sh dist      # construit les ZIP installables
```

**Publier une version** : mettre à jour la version dans `ssm-connector.php` (en-tête et constante), `readme.txt` (*Stable tag*) et `CHANGELOG.md`,
fusionner dans `main`, puis lancer le workflow **Release** (Actions › Release › Run workflow) avec le numéro de version.
Il contrôle la cohérence, exécute les tests, crée le tag et la release avec `ssm-connector-wp.zip` et `ssm-connector-wp-vX.Y.Z.zip`.
