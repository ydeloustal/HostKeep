# Changelog

Toutes les évolutions notables de Hostkeep. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [Semantic Versioning](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Recherche universelle (`Ctrl K`, `/` ou la loupe) : ouvre n'importe quelle page ou section et lance les actions courantes.
- Accueil : bandeau d'état général, alertes triées par gravité et actions rapides (contrôler les sites, sauvegarder, chercher les mises à jour, lancer l'audit).
- Raccourcis clavier (`1` à `5`, `R`, `?`) et lien « Aller au contenu ».
- Champ de filtre sur les tableaux de plus de 8 lignes.
- Notifications : une action continue en arrière-plan quand on ferme sa fenêtre, et annonce son résultat.
- Bandeau « Connexion au serveur perdue » quand l'API ne répond plus.
- Connexion : bouton Afficher / Masquer le mot de passe, avertissement de verrouillage des majuscules.

### Modifié
- Barre du haut allégée sur mobile : thème, installation et déconnexion passent dans un menu « Plus ».
- La barre d'onglets du bas est gardée jusqu'à 1024 px (tablettes), la barre latérale prend le relais au-delà.
- Graphiques utilisables au doigt (un appui affiche la valeur).
- Le relevé en direct se met en pause quand l'onglet est en arrière-plan.
- Texte de base à 16 px sur mobile.

### Corrigé
- La pastille de section active restait parfois sur la mauvaise section pendant le chargement.
- Le focus clavier pouvait sortir de la fenêtre d'action.

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
