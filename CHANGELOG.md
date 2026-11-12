# Changelog

Toutes les évolutions notables de Hostkeep. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [Semantic Versioning](https://semver.org/lang/fr/).

## [1.0.0] — 2026-11-12

Première version publique.

### Ajouté
- Interface en 5 pages (Accueil, Sites, Système, Sécurité, Données) au lieu de 17 onglets, avec pastilles d'accès rapide et liens profonds.
- Version mobile-first : barre d'onglets en bas, tableaux affichés en cartes, actions dans une feuille qui monte du bas, zones tactiles de 44 px.
- Configuration par fichier `.env` commenté (`.env.example`) : domaine, identifiant, durée de session, version de PHP, services, dossiers consultables.
- Détection automatique des services systemd, de la version de PHP-FPM et des crontabs.
- Installateur générique pour Debian / Ubuntu : `deps`, `app`, `apache`, `nginx`, `uninstall [--purge]`.
- Configuration Nginx (en plus d'Apache) et certificat Let's Encrypt par la méthode webroot, avec rechargement automatique au renouvellement.
- Collecte par timers systemd (journaux dans `journalctl`).
- Maquettes mobiles statiques et captures d'écran dans `docs/`.

### Modifié
- Projet renommé en **Hostkeep** (utilisateur système, base, chemins, services).
- Thème sombre : contraste du bouton principal corrigé.

### Corrigé
- fail2ban sur les systèmes sans `/var/log/auth.log` (Debian 12 et suivants) : la jail `sshd` lit le journal systemd.
- Configuration Nginx compatible avec les versions antérieures à 1.25.1 (Debian 12), qui ne connaissent pas `http2 on;`.
- L'installation ne bloque plus si PHP-FPM ou le serveur web sont arrêtés ; la désinstallation va au bout même si Nginx est arrêté.

[1.0.0]: https://github.com/ydeloustal/hostkeep/releases/tag/v1.0.0
