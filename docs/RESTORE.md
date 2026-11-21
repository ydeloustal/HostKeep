# Restaurer Hostkeep sur un nouveau serveur

Cette archive contient de quoi réinstaller le panel **à l'identique** :

| Dossier / fichier | Contenu |
|---|---|
| `source/` | le code exact de la version installée |
| `config/manifest.json` | version, commit, domaine, serveur web, date |
| `config/settings.json` | les réglages du panel, **sans aucun secret** |
| `config/accounts.json.enc` | (si demandé) les comptes, chiffrés par ta phrase de passe ; jamais les secrets de double authentification |
| `config/env.public` | la configuration du serveur, sans clé ni mot de passe |
| `restore.sh` | la restauration en une commande |

## En 3 étapes

1. Sur le nouveau serveur (Debian 12/13 ou Ubuntu 22.04/24.04), fais pointer le DNS du domaine du panel vers lui.
2. Copie l'archive, puis :

   ```bash
   tar -xzf hostkeep-migration-*.tar.gz
   cd hostkeep-migration-*/
   sudo bash restore.sh
   ```

   Le script confirme le domaine, installe Hostkeep, réimporte les réglages et les comptes (il demande la phrase de passe), puis publie le panel.
3. Dans le panel, ressaisis les secrets listés à la fin (SMTP, sauvegarde externe, alertes, ping), puis rapatrie les données :

   ```bash
   sudo hostkeep restore fetch                       # jours disponibles sur la copie externe
   sudo hostkeep restore fetch 2026-11-20            # rapatrie un jour
   sudo hostkeep restore db 2026-11-20 ma_base       # restaure une base
   sudo hostkeep restore files 2026-11-20 www --in-place
   ```

Options : `--domain D` (autre domaine), `--email E` (Let's Encrypt), `--yes` (sans confirmation ; la phrase de passe des comptes reste demandée s'il y a un terminal).

> Les comptes MariaDB utilisés par tes sites vivent dans la base système `mysql`, qui n'est pas restaurée automatiquement : recrée-les (`CREATE USER … ; GRANT …`) après avoir restauré les bases.
