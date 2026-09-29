---
name: ssm-connector-wp
description: "SSM Connector WordPress — MU-plugin + WP-CLI. Inventaire, MAJ, logs, sécurité. Livré en ZIP installable manuellement."
---

# SSM Connector — WordPress

MU-plugin WordPress qui expose le site au SSM Core (inventaire, MAJ, logs, sécurité).

## Installation (manuelle côté client)

1. Télécharger `ssm-connector.zip` depuis la release GitHub
2. Dézipper dans `wp-content/plugins/` ou `wp-content/mu-plugins/` (préféré)
3. Activer via WP-CLI : `wp plugin activate ssm-connector`
4. Récupérer le token généré : `wp ssm token`
5. Ajouter le site dans le dashboard SSM avec ce token

## Routes REST exposées

```
POST /wp-json/ssm/v1/heartbeat          # inventaire + healthcheck
GET  /wp-json/ssm/v1/extensions          # plugins + thèmes
POST /wp-json/ssm/v1/update              # déclenche MAJ
POST /wp-json/ssm/v1/log                 # push log batch
POST /wp-json/ssm/v1/security-event      # push event sécu
GET  /wp-json/ssm/v1/status              # statut rapide
```

## Compatibilité

- WordPress 5.5 minimum (recommandé 6.x LTS)
- PHP 7.4 minimum (recommandé 8.1+)
- MySQL 5.7+ / MariaDB 10.3+
- Multisite : supporté

## Sécurité

- Auth par token 64 chars hashé SHA-256
- IP allowlist optionnelle (IP serveur SSM)
- Toutes routes : nonce + capability check

## Roadmap

- [x] Sprint 0 — Spike (1 semaine)
- [ ] Sprint 2 — Full MVP (3 semaines)