# Tests d'intégration : installation réelle et attaques

Ces tests installent Hostkeep **pour de vrai** dans un conteneur jetable qui fait tourner systemd (comme un VPS), puis tentent ce qu'un compte ne devrait pas pouvoir faire : actions d'un Lecteur, lecture des secrets du panel par un Administrateur, injections dans les arguments du helper root, configuration rclone dangereuse, CSRF, force brute…

```bash
bash tests/integration/run.sh jrei/systemd-debian:12 nginx
```

```bash
bash tests/integration/run.sh jrei/systemd-ubuntu:22.04 apache
```

```bash
bash tests/integration/run.sh jrei/systemd-debian:13 caddy
```

- Il faut Docker ; le conteneur est lancé en mode privilégié (systemd) et supprimé à la fin (`KEEP=1` pour le garder).
- Les trois comptes de test reçoivent un mot de passe aléatoire, jamais affiché.
- La double authentification obligatoire est désactivée **dans le conteneur seulement**, pour pouvoir se connecter avec `curl`.
- Sortie attendue : `TOUTES LES ATTAQUES ONT ÉTÉ BLOQUÉES`.

Ces tests ne tournent pas encore dans le CI (ils prennent quelques minutes par distribution) : lance-les avant chaque version.

## Résultats du 22/11/2026 (2.10.0 + correctifs 2.10.1)

| Distribution | Serveur web | PHP | Installation | Attaques |
|---|---|---|---|---|
| Debian 12 | Nginx | 8.2 | ✅ | ✅ toutes bloquées |
| Debian 12 | Apache | 8.2 | ✅ | — |
| Debian 12 | Caddy | — | ⚠️ refus propre : Caddy n'est pas dans les dépôts de Debian 12 | — |
| Debian 12 | proxy (`--trusted-proxy`) | 8.2 | ✅ (usurpation d'IP refusée) | — |
| Debian 13 | Caddy | 8.4 | ✅ | — |
| Ubuntu 24.04 | Nginx | 8.3 | ✅ | ✅ toutes bloquées |
| Ubuntu 22.04 | Apache | 8.1 | ✅ | ✅ toutes bloquées |
