# Changelog

Toutes les évolutions notables de Hostkeep. Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
versions selon [Semantic Versioning](https://semver.org/lang/fr/).

## [Non publié]

### Sécurité
- **Sauvegarde externe, destination « Autre » : la configuration rclone collée est désormais une liste blanche.** rclone tourne en root dans le collecteur ; certaines de ses options lancent un programme ou lisent un fichier local (`ssh`, `*_command`, `*_file`, backends `local`, `alias`, `union`, `crypt`…), ce qui permettait à un compte Administrateur d'exécuter des commandes en root. Seuls les backends de stockage distants courants et les options d'identification et d'adresse sont acceptés ; le refus nomme l'option en cause.
- La règle est vérifiée à l'enregistrement **et** à chaque écriture de `rclone.conf` : une configuration enregistrée avant cette version et devenue invalide fait échouer la copie externe avec une alerte qui explique quoi corriger (rien n'est envoyé tant qu'elle n'est pas corrigée).
- `rclone.conf` : une valeur contenant un caractère de contrôle (saut de ligne…) est refusée, pour qu'aucun réglage ne puisse ajouter d'options.
- **Un Administrateur ne peut plus obtenir les secrets du panel.** Il pouvait lire `/var/www/hostkeep/.env` (clé de chiffrement de tous les secrets) dans l'explorateur et télécharger la base `hostkeep`, puis déchiffrer les secrets de double authentification des Super Admins et les identifiants enregistrés.
  - Explorateur : les fichiers de Hostkeep (`/var/www/hostkeep`, `/etc/hostkeep`, `/var/lib/hostkeep`, `/var/backups/hostkeep`) sont toujours refusés, y compris par lien symbolique ou `..`, quelles que soient les racines configurées.
  - Nouvelle permission **`backups.panel`** (Super Admin) pour télécharger ou restaurer la base `hostkeep`, sa copie avant restauration, l'archive de fichiers `hostkeep` et l'archive complète d'un jour (qui les contient).
  - Le `.env` du panel n'est plus dans l'archive `www` : il est sauvegardé dans l'archive `hostkeep`. Restaurer les deux le remet en place.
  - Les archives `www` faites **avant** cette version contiennent encore ce `.env` : elles disparaissent à la fin de la conservation des fichiers (3 jours par défaut).

### Tests
- Premiers tests automatisés, exécutés par le CI : `tests/php/` (règles rclone, rôles et sauvegardes sensibles, sous PHP 8.1 et la version du runner) et `tests/helper/` (règles de l'explorateur du helper root, sans root).

## [2.9.0] — 2026-11-21

### Ajouté
- **Sites servis par Caddy** : découverts depuis la configuration en cours (API d'admin) ou le Caddyfile (`caddy adapt`) ; disponibilité, temps de réponse, racine, sockets PHP-FPM, proxy ; certificats obtenus par Caddy suivis (colonne « Géré par ») ; `/etc/caddy` ajouté aux sauvegardes de fichiers.
- Audit : pour Caddy, bloc `(hostkeep_securite)` prêt à coller (en-têtes sans écraser ceux des sites, fichiers sensibles en 403).

- **Application installable (PWA) renforcée** : manifest complet (raccourcis Accueil, Sauvegardes, Audit, Sites ; captures pour la fenêtre d'installation ; `display_override`), mêmes balises sur toutes les pages (connexion, mot de passe, double authentification), installation possible dès la page de connexion.

### Modifié
- Service Worker v4, **Network-First sans cache HTTP** (`cache: 'no-cache'`) : une mise à jour de Hostkeep s'affiche au chargement suivant ; `sw.js` jamais mis en cache (`updateViaCache: 'none'`), vérifié à chaque retour sur l'appli, message « Hostkeep a été mis à jour » avec bouton Recharger. Le cache ne sert que de secours hors ligne ; l'API et les pages connectées ne sont jamais stockées.
- CSS et JS appelés avec `?v=<version>` : aucun cache (navigateur, proxy) ne garde une ancienne version après une mise à jour.

### Documentation
- README : tableau de compatibilité Apache / Nginx / Caddy / proxy, structure du dépôt à jour.

## [2.8.0] — 2026-11-21

### Ajouté
- **Audit actionnable** (*Sécurité › Audit*) : carte « Corrections automatiques » et bouton **Corriger** sur les constats concernés. Trois corrections globales et réversibles : en-têtes de sécurité (Apache `setifempty`, HSTS seulement en HTTPS, 6 mois), fichiers sensibles bloqués (`.env`, `.git`, dumps, `composer.json`, phpinfo, listes de dossiers), version du serveur masquée. Chacune : un fichier, `configtest` / `nginx -t`, retour arrière automatique, rechargement, nouvel audit et compte rendu (avec, pour Nginx, la ligne `include` à ajouter et les fichiers concernés). Bouton **Retirer**.
- **Prévision du disque** : tendance du remplissage de `/` sur 30 jours (pic quotidien, moindres carrés), affichée sur l'accueil et dans *Système › Stockage* ; alerte sous 14 jours, critique sous 3.

### Modifié
- `system/apache-hardening.conf` déplacé dans `system/hardening/` (avec les nouveaux fichiers), bloque aussi `composer.json` / `composer.lock`. Les fichiers de correction sont installés dans `/usr/local/share/hostkeep/hardening/`.

## [2.7.0] — 2026-11-21

### Ajouté
- **Archive de migration** (*Administration › Version*, Super Admin) : un `.tar.gz` avec le code de la version installée, les réglages sans aucun secret, les comptes chiffrés par une phrase de passe (AES-256-GCM, PBKDF2 ; optionnel, jamais les secrets de double authentification), la config serveur sans clé, une notice et **`restore.sh`**.
- **`restore.sh`** : sur un nouveau serveur, installe Hostkeep (même branche depuis GitHub, ou le code de l'archive hors ligne), réimporte réglages et comptes, publie le panel sur le domaine et liste ce qu'il reste à faire.
- `sudo hostkeep restore db <jour> <base>` (copie de l'état actuel avant), `user.php import-config`.
- Installateur : vérifications affichées avant toute modification (espace disque, mémoire, ports 80/443, DNS du domaine).
- README : tableau « En bref » (installer, mettre à jour, sauvegarder, restaurer, changer de serveur, désinstaller) et section « Changer de serveur ».

## [2.6.0] — 2026-11-21

### Ajouté
- **Surveillance externe (« dead man's switch »)** : *Paramètres › Surveillance externe*, URL de ping Healthchecks.io, Uptime Kuma (Push) ou autre, appelée chaque minute par le collecteur, avec signal d'échec quand une tâche de collecte échoue ; bouton de test, dernier ping réussi, alerte locale si le ping échoue.
- **Alertes sur Discord, Telegram et webhook JSON** en plus de l'e-mail (*Paramètres › Alertes*) : même message sur chaque canal, bouton « Tester Discord / Telegram / webhook » avec le résultat de chacun. Les URL et jetons sont stockés chiffrés et vérifiés à l'enregistrement.

### Modifié
- *Paramètres › Alertes par e-mail* devient *Alertes* ; l'interrupteur e-mail devient un canal parmi d'autres. Une alerte envoyée sur au moins un canal n'est pas renvoyée ; si tous échouent, nouvel essai 5 minutes plus tard.

## [2.5.0] — 2026-11-21

### Ajouté
- **Double authentification (TOTP, RFC 6238)** compatible Aegis, Google Authenticator, FreeOTP, Bitwarden, 1Password : page `/2fa.php` avec QR code (dessiné par le serveur, sans dépendance) et clé à saisir à la main, 10 codes de secours à usage unique, régénération des codes, désactivation (mot de passe + code).
- Connexion en deux étapes : après le mot de passe, code à 6 chiffres ou code de secours (5 essais, 5 minutes, compté dans la limite de tentatives). Un code déjà utilisé est refusé.
- Politique *Paramètres › Sécurité › Double authentification obligatoire* : pour les Administrateurs et Super Admins (par défaut), pour tous, ou pour personne. Un compte concerné sans 2FA ne peut rien faire d'autre que l'activer.
- *Mon compte* : état de la 2FA et codes de secours restants. *Utilisateurs* : colonne « Double auth. » et réinitialisation par un compte de rang supérieur (sessions fermées). `sudo hostkeep 2fa-reset <identifiant>` en dernier recours.

### Modifié
- **À la mise à jour, les Administrateurs et Super Admins devront activer la double authentification dès leur prochaine page** (un téléphone avec une application TOTP suffit). Pour l'éviter : *Paramètres › Sécurité* › « Pour personne ».

## [2.4.0] — 2026-11-21

### Ajouté
- **Sauvegarde des fichiers** en plus des bases : `/var/www`, `/etc/apache2`, `/etc/nginx`, `/etc/letsencrypt` et `/etc/hostkeep`, en archives `files/<nom>.tar.gz` (root seulement), avec exclusions réglables, conservation séparée (3 jours par défaut) et vérification de l'espace disque avant de commencer.
- `manifest.json` dans chaque sauvegarde : contenu, tailles, sommes SHA-256 et erreurs.
- **Copie externe avec rclone** (*Paramètres › Sauvegarde externe*) : S3, SFTP ou toute configuration rclone (Google Drive, Dropbox…), chiffrement rclone crypt, conservation à distance, limite de débit, bouton *Tester la connexion*, envoi juste après chaque sauvegarde et nouvel essai toutes les heures.
- Accueil et *Données › Sauvegardes* : dernière sauvegarde, dernière copie externe réussie, téléchargement de l'**archive complète** d'un jour (ou d'une archive de fichiers) en un clic ; alertes si la sauvegarde est incomplète ou si la copie externe échoue ou date de plus de 36 h.
- Commandes `hostkeep backup`, `hostkeep restore list`, `hostkeep restore fetch [jour]` (rapatriement depuis la copie externe, sommes vérifiées) et `hostkeep restore files <jour> <archive> [--in-place]`.

### Modifié
- rclone fait partie des prérequis (installé aussi lors d'une mise à jour).
- Le PHP-FPM du panel laisse jusqu'à 6 h à une requête (téléchargement en flux d'une grosse sauvegarde) ; le temps CPU reste limité.

## [2.3.0] — 2026-11-21

### Ajouté
- **Redémarrer le serveur** depuis *Sécurité › Audit* (rôle administrateur, confirmation) : régénère le menu GRUB s'il ne propose pas le dernier noyau, puis redémarre 10 s plus tard.
- Carte *Redémarrage* : heure du dernier démarrage, et diagnostic quand le serveur a redémarré mais tourne toujours sur l'ancien noyau (GRUB non régénéré, `GRUB_DEFAULT`, noyau imposé par l'hébergeur).

### Corrigé
- Juste après un redémarrage, « Redémarrage requis » restait affiché jusqu'à 5 min (données de l'ancien démarrage) : le noyau en cours est désormais lu en direct, et la collecte « 5 min » passe aussi 40 s après le démarrage.
- « Redémarrage requis » ne compare plus que des noyaux de la même variante (`amd64` / `cloud-amd64`).

## [2.2.1] — 2026-11-20

### Corrigé
- Nginx : le journal de trafic ne s'activait pas quand un autre fichier de `conf.d` (chargé après) définissait déjà le format `hostkeep_traffic`. Hostkeep utilise désormais son propre format, `hostkeep_vhost`.

## [2.2.0] — 2026-11-20

### Ajouté
- **Nginx pris en charge partout**, comme Apache : sites lus dans `nginx -T` (includes et snippets suivis : racine, certificat, socket PHP), contrôles HTTP et disponibilité, certificats, activation / désactivation d'un site (sites-enabled, `nginx -t` et retour arrière), journaux Nginx, pools PHP-FPM reliés à leurs sites, mémoire de Nginx, audit (`server_tokens`).
- Trafic avec Nginx : journal `conf.d/hostkeep-traffic.conf` créé à l'installation (sans redéfinir un format existant), snippet `hostkeep-traffic.conf` à inclure ; la page *Trafic* liste les sites Nginx non comptés.
- Apache et Nginx peuvent tourner ensemble (ex. Apache derrière Nginx) : les sites sont fusionnés par nom et chaque configuration indique son serveur.

### Corrigé
- Erreur affichée après l'enregistrement du profil (*Mon compte*), alors que l'enregistrement avait réussi.
- Apache : un vhost seul sur son port n'était pas détecté.

## [2.1.1] — 2026-11-20

### Corrigé
- « null » affiché sous les graphiques à une seule courbe (CPU, Disque, Charge).
- Accueil : titre « En direct » collé à la description de la page.

## [2.1.0] — 2026-11-20

### Modifié
- Navigation repensée : barre latérale groupée (Surveillance, Protection, Gestion), état du serveur dans une carte, menu compte unique (Mon compte, thème, raccourcis, déconnexion).
- Chaque page s'affiche en onglets (une vue à la fois) au lieu d'empiler toutes ses sections ; les onglets portent le nombre d'alertes et se souviennent du dernier choix.
- Mobile : barre du haut réduite à l'essentiel (logo, état, recherche, avatar), 5 onglets en bas.
- Paramètres regroupés dans un seul onglet avec un sous-menu ; « Version du panel » passe dans Administration, « Processus » dans Système.

## [2.0.1] — 2026-11-20

### Corrigé
- Mise à jour depuis la 1.x : toutes les configurations du serveur web qui utilisaient l'ancien socket PHP-FPM (y compris les snippets écrits à la main) sont basculées sur `hostkeep.service` ; en cas d'échec du test de configuration, rien n'est retiré.

## [2.0.0] — 2026-11-20

### Ajouté
- **Installation en une ligne** (`curl … | sudo bash`) : récupère le dépôt, vérifie et installe seulement les prérequis manquants, pose les questions utiles (ou aucune avec `--yes`) et crée le premier compte.
- **Détection du serveur web** (Apache, Nginx, Caddy, Traefik / HAProxy / conteneur sur les ports 80/443) : le panel est ajouté comme nouveau site sans modifier les autres ; refus si le domaine est déjà servi ; test de syntaxe et retour automatique à la configuration précédente en cas d'erreur. HTTPS Let's Encrypt optionnel.
- Mode **reverse proxy** : instance HTTP locale dédiée (`hostkeep-http.service`), route Traefik générée si possible, exemples HAProxy / Nginx Proxy Manager.
- **`hostkeep.service`** : PHP-FPM dédié lancé par systemd, redémarrage automatique, sandboxing.
- Commande **`hostkeep`** : `status`, `admin`, `users`, `user create`, `passwd`, `unlock`, `web`, `update`, `logs`, `uninstall`.
- **Comptes utilisateurs** en base, connexion par identifiant ou e-mail, mot de passe temporaire à changer à la première connexion, invitation et « mot de passe oublié » par e-mail, blocage par compte après plusieurs échecs.
- **Rôles et permissions (RBAC)** : Super Admin, Administrateur, Éditeur / Modérateur, Lecteur ; vérifiés côté serveur sur chaque route et chaque action root, interface adaptée au rôle.
- **Sessions** listées par appareil et révocables (soi-même ou par un administrateur).
- Page **Administration** : utilisateurs, matrice des rôles, paramètres (général, SMTP avec e-mail de test, alertes par e-mail, sauvegardes, sécurité avec liste d'IP autorisées).
- Page **Mon compte** : profil, mot de passe, sessions.
- **Alertes par e-mail** (nouvelles alertes et résolutions), client SMTP intégré (STARTTLS / TLS), mot de passe SMTP chiffré.
- Changement de **domaine** depuis le panel (Super Admin).
- Journal des actions enrichi : connexions, comptes, paramètres, téléchargements.

### Modifié
- Heure, activation et durée de conservation des sauvegardes réglables depuis le panel.
- Cookie de session `Secure` selon le protocole réel (HTTPS direct ou via le proxy), HSTS envoyé par l'application.
- La protection Apache globale (`hardening.conf`) n'est plus activée d'office : elle s'applique à tous les sites, elle est désormais proposée (`--harden-apache`).

### Migration depuis 1.x
- Le compte unique du `.env` est importé comme Super Admin (même mot de passe) ; `set-password.php` reste utilisable.
- L'ancien pool PHP-FPM est retiré et le vhost existant est basculé sur `hostkeep.service` automatiquement.

## Refonte de l'interface (incluse dans 2.0.0)

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
- Sur grand écran, la croix de fermeture des fenêtres s'étirait sur toute la largeur au lieu de rester à droite.

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
