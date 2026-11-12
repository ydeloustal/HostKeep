# Admin VPS — tableau de bord du serveur mmi24c04

URL : https://admin.mmi24c04.mmi-troyes.fr

## Définir / changer le mot de passe
```bash
ssh mmi24c04@mmi24c04.mmi-troyes.fr
sudo php /var/www/admin-dashboard/bin/set-password.php
```

## Architecture
- PHP 8.4 natif + JS vanilla (graphes SVG maison), aucun démon : pool FPM `dashboard` en ondemand.
- La page tourne sous l'utilisateur système `dashboard`, sans droit root. Base MariaDB `dashboard` via le socket unix (pas de mot de passe stocké).
- Collecteur root : `/etc/cron.d/dashboard` → `bin/collect.php` (minute / 5min / hourly / purge). Historique gardé 30 jours.
- Actions : `/usr/local/sbin/dashboard-action` (liste blanche, chaque argument validé), autorisé par `/etc/sudoers.d/dashboard`. Les actions tournent en tâches systemd (`dashboard-job-*`), sortie dans `/var/lib/dashboard/jobs/`.
- Sécurité : session Secure/HttpOnly/SameSite=Strict, expiration après 30 min, CSRF, CSP stricte, 5 essais / 15 min par IP, et jail fail2ban `dashboard` (bannissement 1 h).

## Mettre à jour
Depuis ce dossier :
```bash
tar czf - . | ssh mmi24c04@mmi24c04.mmi-troyes.fr 'T=$(mktemp -d); tar xzf - -C "$T"; sudo bash "$T/install.sh" app; rm -rf "$T"'
```

## Désinstaller
```bash
sudo a2dissite admin-dashboard admin-dashboard-le-ssl && sudo apache2ctl configtest && sudo systemctl reload apache2
sudo rm /etc/cron.d/dashboard /etc/sudoers.d/dashboard /usr/local/sbin/dashboard-action /etc/php/8.4/fpm/pool.d/dashboard.conf /etc/fail2ban/jail.d/dashboard.conf /etc/fail2ban/filter.d/dashboard.conf /etc/logrotate.d/dashboard
sudo systemctl reload php8.4-fpm && sudo fail2ban-client reload
```
