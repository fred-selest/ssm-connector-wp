<p align="center"><img src="assets/icon-256x256.png" alt="Logo SSM Connector" width="128" height="128"></p>

# SSM Connector — WordPress

Extension WordPress qui envoie à **SSM Core** (Selest Site Manager) l'inventaire du site : version de WordPress, de PHP et de la base,
extensions et thèmes (avec les mises à jour disponibles). L'envoi est automatique, toutes les heures.

- **Facile** : une adresse, un token, un bouton **Connecter**. La connexion est testée aussitôt, le résultat est expliqué en clair.
- **Sûre** : n'ouvre **aucune porte** sur le site (elle envoie, elle n'écoute rien), token chiffré, https obligatoire hors réseau privé.
- **À jour** : se met à jour comme n'importe quelle extension WordPress, depuis les releases GitHub.

Elle ne modifie rien sur le site : elle ne fait que lire et envoyer.

## Aperçu

| | |
|---|---|
| **Avant la connexion** : le guide en trois étapes<br>![Pas encore connecté](docs/screenshots/01-pas-encore-connecte.png) | **Connecté** : état, dernier envoi, ce qui est protégé<br>![Connecté](docs/screenshots/02-connecte.png) |
| **Un échec expliqué** : la cause et la marche à suivre<br>![Échec : token refusé](docs/screenshots/03-echec-token.png) | **Rappel** sur la liste des extensions tant que le site n'est pas connecté<br>![Rappel sur la liste des extensions](docs/screenshots/04-rappel-extensions.png) |
| **Mise à jour disponible**, avec le logo<br>![Mise à jour disponible](docs/screenshots/05-mise-a-jour.png) | **Configuration dans `wp-config.php`** : champs verrouillés, token hors base de données<br>![Configuration dans wp-config.php](docs/screenshots/06-wp-config.png) |

## Installation

1. Télécharger `ssm-connector-wp.zip` depuis la [dernière release](https://github.com/fred-selest/ssm-connector-wp/releases/latest).
2. WordPress : **Extensions › Ajouter › Téléverser**, puis **Activer**.
   En ligne de commande : `wp plugin install ssm-connector-wp.zip --activate`.

Variante *mu-plugin* : copier **le fichier** `ssm-connector.php` directement dans `wp-content/mu-plugins/` (pas dans un sous-dossier :
WordPress n'y charge que les fichiers du premier niveau). Rien à activer ; pas de mises à jour automatiques dans ce cas.
Ne pas installer les deux variantes à la fois.

## Configuration

1. Dans SSM : **Sites**, bouton **🔌** du site, puis copier le **token** (il n'est affiché qu'une fois ; un nouveau token se génère au même endroit et invalide l'ancien).
2. Dans WordPress : **Réglages › SSM Connector**. Coller le token et saisir l'adresse de SSM : le plus simple est de la copier depuis la barre d'adresse du navigateur, seule la partie `https://nom-de-domaine` est conservée.
3. Cliquer sur **Connecter**. Les réglages sont enregistrés (le token chiffré) et un premier envoi est fait tout de suite : le résultat s'affiche en haut de la page.

Tant que le site n'est pas connecté, un rappel s'affiche sur le Tableau de bord et sur la liste des extensions.

### En ligne de commande

```
echo "$TOKEN" | wp ssm connect https://ssm.exemple.fr    # enregistre et teste (le token ne reste pas dans l'historique du shell)
wp ssm status                                           # configuration et dernier envoi (4 derniers caractères du token seulement)
wp ssm heartbeat                                        # renvoie l'inventaire maintenant
```

### Dans `wp-config.php` (déploiements en série, hébergeurs gérés)

```php
define('SSM_CONNECTOR_URL',   'https://ssm.exemple.fr');
define('SSM_CONNECTOR_TOKEN', 'le-token-du-site');
```

Ces constantes l'emportent sur la base de données ; **le token n'est alors jamais écrit dans la base**. Les champs de la page sont verrouillés.
Depuis WP-CLI : `wp config set SSM_CONNECTOR_TOKEN "$TOKEN" --type=constant`.

## Ce qui est envoyé

À chaque envoi, en HTTPS vers `<adresse>/api/v1/heartbeat`, avec l'en-tête `X-SSM-Token` :

| Donnée | Pourquoi |
|---|---|
| version de WordPress, de PHP et de la base, serveur web, nom de la machine, chemin d'installation | fiche du site dans SSM |
| extensions : identifiant, nom, version, active ou non, mise à jour disponible et version proposée | suivi des mises à jour et des vulnérabilités |
| thèmes : mêmes champs, plus le thème parent | idem |
| version du connecteur | savoir quels sites sont à jour |

Rien d'autre : ni utilisateurs, ni contenu, ni e-mails, ni identifiants de connexion.

## Sécurité

- **Aucune porte d'entrée** : l'extension n'enregistre aucune route (ni REST, ni AJAX public) et n'écoute rien. Elle envoie à SSM, c'est tout.
- **Token chiffré dans la base** (libsodium, clé dérivée des clés de sécurité de `wp-config.php`) : une sauvegarde de base ou une injection SQL en lecture ne révèle pas le token. Si ces clés changent, la page le signale et il suffit de recoller le token. Pour ne jamais l'écrire dans la base : constantes dans `wp-config.php`.
- **https obligatoire** hors réseau privé : une adresse `http://` vers Internet est refusée. Le certificat est toujours vérifié (il n'existe aucune option pour l'ignorer) et les redirections ne sont jamais suivies, pour que le token n'aille pas ailleurs.
- Le token n'est **jamais affiché en entier** (4 derniers caractères), jamais prérempli, jamais cité dans un message d'erreur.
- Page de réglages et boutons réservés à `manage_options`, protégés par un jeton anti-CSRF.
- Données minimales : voir ci-dessus.

Ce que cela ne couvre pas : un attaquant qui lit `wp-config.php` peut déchiffrer le token de la base (d'où l'option des constantes, qui évite le stockage mais pas la lecture du fichier) ; et le token ouvre l'API d'envoi de **ce site seul** côté SSM.

## Mises à jour

L'extension se met à jour depuis les releases GitHub de ce dépôt, avec les outils habituels de WordPress : **Extensions** (bouton « Mettre à jour »), **Tableau de bord › Mises à jour**, `wp plugin update ssm-connector`, ou automatiquement si l'administrateur active « Activer les mises à jour automatiques » sur la ligne de l'extension. WordPress vérifie deux fois par jour ; le bouton **Rechercher une mise à jour** de la page de réglages le fait tout de suite.

- Le paquet doit venir des releases de ce dépôt et sa somme **SHA-256** (fournie par GitHub) est contrôlée avant toute installation ; en cas d'écart, rien n'est installé.
- Le dossier installé est conservé, même s'il ne s'appelle pas `ssm-connector`.
- **Le dépôt doit être public** : aucun jeton GitHub n'est stocké sur les sites. Tant qu'il est privé, la page affiche « aucune release trouvée (dépôt introuvable ou privé) » ; le reste fonctionne.
- Désactiver : `define('SSM_CONNECTOR_DISABLE_UPDATES', true);` dans `wp-config.php`.
- Quiconque peut publier une release sur ce dépôt peut donc faire installer du code sur les sites qui ont activé les mises à jour automatiques : protéger le compte GitHub (authentification à deux facteurs).

## Compatibilité

WordPress 5.5 ou plus, PHP 7.4 ou plus (testé en CI sur 7.4, 8.1 et 8.3). Multisite : l'inventaire est celui du site sur lequel l'extension est active.

## Développement

```
php tests/run.php                 # tests sans WordPress (faux appels WP) + constantes de wp-config.php
sh scripts/check-version.sh       # la version est-elle la même partout ?
sh scripts/build-zip.sh dist      # construit les ZIP installables
```

**Publier une version** : mettre à jour la version dans `ssm-connector.php` (en-tête et constante), `readme.txt` (*Stable tag*) et `CHANGELOG.md`,
fusionner dans `main`, puis lancer le workflow **Release** (Actions › Release › Run workflow) avec le numéro de version.
Il contrôle la cohérence, exécute les tests, crée le tag et la release avec `ssm-connector-wp.zip` et `ssm-connector-wp-vX.Y.Z.zip`.
