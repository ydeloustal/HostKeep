# Catalogue des fonctionnalités

> Catalogue exhaustif de ce que Hostkeep **pourrait** proposer à terme, domaine par domaine.
> Ce n'est pas une liste de tâches : l'état actuel, les écarts et les priorités sont dans `FEATURE_GAP_ANALYSIS.md`, l'ordre de réalisation dans `PRODUCT_ROADMAP.md`.

**Colonnes**
- **ID** : identifiant stable, à réutiliser dans les tickets, branches et commits (`feat(NET-02): …`).
- **Place** : **Cœur** (livré et maintenu avec Hostkeep) · **Ext.** (extension officielle, installable à la demande) · **Hors** (hors périmètre : on ne le fera pas, ou via intégration externe).
- **Valeur** : ★★★ indispensable à la promesse · ★★ forte valeur · ★ confort.
- **Public** : P = particulier / homelab · F = freelance, agence web · E = entreprise, équipe IT · H = hébergeur / MSP (multi-clients).

---

## A. Infrastructure

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| INF-01 | Gestion multi-serveurs | Inscrire, regrouper (étiquettes, groupes), retirer des serveurs ; vue d'ensemble de la flotte | Cœur | ★★★ | P F E H |
| INF-02 | Agent léger | Agent installé sur chaque serveur, connexion sortante chiffrée, opérations typées | Cœur | ★★★ | tous |
| INF-03 | Inventaire matériel | CPU, RAM, disques (SMART), cartes réseau, virtualisation détectée, fournisseur cloud | Cœur | ★★ | tous |
| INF-04 | Distribution et noyau | Version, fin de support, noyau courant / installé, redémarrage requis | Cœur | ★★★ | tous |
| INF-05 | Services systemd | Lister toutes les unités, démarrer / arrêter / redémarrer / activer / désactiver, journaux par unité, dépendances | Cœur | ★★★ | tous |
| INF-06 | Processus | Liste triable, arbre, signal (TERM/KILL), priorité (renice), limites (cgroups) | Cœur | ★★ | tous |
| INF-07 | Ressources | Limites systemd (MemoryMax, CPUQuota) par service, swap, OOM killer | Cœur | ★★ | E H |
| INF-08 | Stockage | Partitions, points de montage, LVM, RAID logiciel, quotas, SMART, prévision de remplissage par partition | Cœur (lecture) / Ext. (écriture LVM/RAID) | ★★ | E H |
| INF-09 | Utilisateurs et groupes Linux | Créer, modifier, verrouiller, groupes, shell, sudo | Cœur | ★★★ | tous |
| INF-10 | Permissions | Propriétaire / mode / ACL sur fichiers et dossiers | Cœur | ★★ | tous |
| INF-11 | Tâches planifiées | Crontabs et timers systemd : créer, modifier, activer, historique d'exécution | Cœur | ★★★ | tous |
| INF-12 | Mises à jour système | Liste, sécurité vs autres, sélection de paquets, simulation, fenêtre de maintenance, unattended-upgrades, historique | Cœur | ★★★ | tous |
| INF-13 | Redémarrage planifié | Redémarrer à une heure donnée, avec notification et vérification au retour | Cœur | ★★ | tous |
| INF-14 | Gestion des paquets | Rechercher, installer, supprimer un paquet (liste blanche configurable) | Cœur | ★★ | P F |
| INF-15 | Réglages système | Fuseau horaire, nom d'hôte, NTP, locale, swap file | Cœur | ★ | tous |
| INF-16 | Compatibilité distributions | Debian, Ubuntu puis RHEL/Alma/Rocky ; ARM64 | Cœur | ★★ | tous |
| INF-17 | Machines virtuelles (libvirt/Proxmox) | Gestion de VM | Hors (intégration Proxmox éventuelle en Ext.) | ★ | E |

## B. Hébergement et applications

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| WEB-01 | Sites : découverte | Lire Apache / Nginx / Caddy existants (déjà fait) | Cœur | ★★★ | tous |
| WEB-02 | Sites : création | Assistant : domaine, type (statique, PHP, reverse proxy, Node, Python), racine, utilisateur, PHP, HTTPS | Cœur | ★★★ | P F H |
| WEB-03 | Modèles de vhost | Modèles par framework (WordPress, Laravel, Symfony, SPA, proxy) pour Nginx, Apache, Caddy | Cœur | ★★ | F H |
| WEB-04 | Édition de vhost | Éditeur avec validation (`nginx -t`), diff, rollback | Cœur | ★★ | F E |
| WEB-05 | Domaines et alias | Alias, redirections (www, HTTPS), domaines multiples par site | Cœur | ★★ | tous |
| WEB-06 | Certificats SSL | Émission (HTTP-01, DNS-01), renouvellement, wildcard, certificat importé, suivi | Cœur | ★★★ | tous |
| WEB-07 | PHP multi-versions | Installer des versions, choisir par site, pools PHP-FPM par site, `php.ini` par site, extensions | Cœur | ★★★ | P F H |
| WEB-08 | Isolation par site | Un utilisateur Linux par site, pool FPM dédié, SFTP chrooté | Cœur | ★★ | F H |
| WEB-09 | Node.js | Versions (nvm/paquets), process manager (PM2 ou unité systemd), reverse proxy auto | Cœur | ★★ | F |
| WEB-10 | Python | Versions, venv, Gunicorn/Uvicorn en unité systemd, reverse proxy | Cœur | ★★ | F |
| WEB-11 | Applications « natives » en unité systemd | Toute appli lancée par une commande, supervisée par systemd | Cœur | ★★ | F E |
| WEB-12 | Déploiement Git | Cloner, mettre à jour, construire (étapes déclarées), lien symbolique de release atomique | Cœur | ★★★ | F E |
| WEB-13 | Intégration GitHub / GitLab / Gitea | Webhooks, clés de déploiement, statut de commit | Cœur | ★★ | F E |
| WEB-14 | Déploiements automatiques | Sur push / tag, avec validation optionnelle | Cœur | ★★ | F E |
| WEB-15 | Variables d'environnement et secrets | Par application et environnement, chiffrées, jamais affichées en clair après saisie | Cœur | ★★★ | F E |
| WEB-16 | Versions et rollback | Historique des releases, retour à la précédente en un clic | Cœur | ★★★ | F E |
| WEB-17 | Environnements | Production / staging / préproduction pour une même application | Cœur | ★★ | F E |
| WEB-18 | Installeurs d'applications | WordPress, Nextcloud… en natif (sans Docker) | Ext. | ★★ | P F |
| WEB-19 | Outils WordPress | Mises à jour plugins, clonage staging, wp-cli | Ext. | ★★ | F H |
| WEB-20 | Cache HTTP (Varnish, cache Nginx) | Activer/purger | Ext. | ★ | F |
| WEB-21 | Pages de maintenance | Basculer un site en maintenance pendant un déploiement | Cœur | ★ | F |
| WEB-22 | Serveur mail complet | Postfix/Dovecot/antispam | Hors (Ext. tierce éventuelle) | ★ | H |
| WEB-23 | Relais SMTP sortant | Configurer un relais pour les mails des applis | Ext. | ★★ | P F |

## C. Docker et conteneurs

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| CTR-01 | Installation de Docker | Installer / mettre à jour Docker Engine officiel ou Podman | Cœur | ★★★ | tous |
| CTR-02 | Conteneurs | Lister, démarrer, arrêter, redémarrer, supprimer, inspecter, statistiques | Cœur | ★★★ | tous |
| CTR-03 | Images | Lister, tirer, supprimer, nettoyer (prune), mises à jour disponibles | Cœur | ★★★ | tous |
| CTR-04 | Volumes | Lister, taille, sauvegarder, supprimer | Cœur | ★★ | tous |
| CTR-05 | Réseaux | Lister, créer, connecter | Cœur | ★ | E |
| CTR-06 | Docker Compose (stacks) | Créer depuis un fichier ou un dépôt Git, éditer, déployer, mettre à jour, journaux | Cœur | ★★★ | tous |
| CTR-07 | Journaux de conteneurs | En direct, recherche, téléchargement | Cœur | ★★★ | tous |
| CTR-08 | Monitoring par conteneur | CPU, RAM, réseau, redémarrages, historique | Cœur | ★★ | tous |
| CTR-09 | Registres | Identifiants de registres privés (chiffrés) | Cœur | ★★ | F E |
| CTR-10 | Catalogue d'applications | Modèles Compose versionnés (dépôt de catalogue signé), paramètres guidés | Ext. (catalogue) sur base Cœur | ★★ | P F |
| CTR-11 | Mises à jour d'images | Détection de nouvelle version, mise à jour avec rollback | Cœur | ★★ | tous |
| CTR-12 | Exposition web | Associer un conteneur à un domaine (reverse proxy + certificat) | Cœur | ★★★ | tous |
| CTR-13 | Console de conteneur | `exec` interactif | Ext. (désactivée par défaut) | ★ | F E |
| CTR-14 | Swarm / Kubernetes | Orchestration | Hors (Portainer, Rancher existent) | — | — |

## D. Bases de données

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| DB-01 | MariaDB / MySQL : bases | Créer, supprimer, renommer (via dump), taille, encodage | Cœur | ★★★ | tous |
| DB-02 | MariaDB / MySQL : utilisateurs et droits | Créer, mot de passe, droits par base, hôtes | Cœur | ★★★ | tous |
| DB-03 | PostgreSQL : bases, rôles, droits | Idem | Cœur | ★★ | F E |
| DB-04 | Redis | État, mémoire, clés (comptage), FLUSH protégé | Ext. | ★ | F |
| DB-05 | MongoDB | État, bases, utilisateurs | Ext. | ★ | F |
| DB-06 | Monitoring | Connexions, requêtes/s, cache, réplication, verrous | Cœur | ★★ | E |
| DB-07 | Import / export | Import SQL (upload), export à la demande | Cœur | ★★★ | tous |
| DB-08 | Sauvegardes par base | Planification et rétention par base, destinations multiples | Cœur | ★★★ | tous |
| DB-09 | Restauration | Vers la même base ou une nouvelle (sans écraser) | Cœur | ★★★ | tous |
| DB-10 | Requêtes lentes | Activer le slow log, top des requêtes normalisées | Cœur | ★★ | F E |
| DB-11 | Analyse de performance | Recommandations (buffer pool, connexions…) façon MySQLTuner | Ext. | ★ | E |
| DB-12 | Client SQL web | Exécuter des requêtes | Ext. (lecture seule par défaut) ; Adminer/pgAdmin via intégration | ★ | F |

## E. Réseau

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| NET-01 | Ports en écoute | Liste avec processus, exposition (public/local), lien vers pare-feu | Cœur | ★★★ | tous |
| NET-02 | Pare-feu UFW | Activer, règles (autoriser/refuser, port, IP), profils | Cœur | ★★★ | tous |
| NET-03 | Pare-feu nftables / firewalld | Mêmes opérations sur un backend nftables natif ou firewalld | Cœur | ★★ | E |
| NET-04 | Protection anti-verrouillage | Toute règle qui couperait SSH ou le panel est refusée ou soumise à un retour arrière automatique (« confirm or revert » en 60 s) | Cœur | ★★★ | tous |
| NET-05 | Interfaces et IP | Lecture des interfaces, IPv4/IPv6, routes | Cœur | ★★ | tous |
| NET-06 | DNS (zones) | Gestion via API des fournisseurs (Cloudflare, OVH, Gandi, Hetzner, Route 53…) | Ext. (une par fournisseur) | ★★ | F H |
| NET-07 | Serveur DNS faisant autorité (BIND/PowerDNS) | — | Hors | — | — |
| NET-08 | Vérification DNS | Enregistrements A/AAAA/CAA/MX d'un domaine, propagation | Cœur | ★★ | tous |
| NET-09 | Connexions actives | Par IP, par port, top talkers | Cœur | ★★ | E |
| NET-10 | Trafic réseau | Par interface, historique | Cœur | ★★ | tous |
| NET-11 | VPN (WireGuard) | Créer un tunnel d'administration | Ext. | ★★ | P E |
| NET-12 | Sondes externes | Ping / TCP / HTTP depuis un autre serveur de la flotte | Cœur | ★★ | E H |

## F. Sécurité

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| SEC-01 | Audit de sécurité | Contrôles hôte et sites (existe), score, historique, export | Cœur | ★★★ | tous |
| SEC-02 | Corrections réversibles | Appliquer / retirer une correction (existe pour 3 cas) ; à étendre (SSH, sysctl, permissions) | Cœur | ★★★ | tous |
| SEC-03 | Vulnérabilités des paquets (CVE) | Croiser paquets installés et avis de sécurité (Debian Security Tracker, Ubuntu USN, OSV) | Cœur | ★★ | E H |
| SEC-04 | Vulnérabilités des images Docker | Scan (Trivy/Grype) | Ext. | ★★ | E |
| SEC-05 | Configuration SSH | Port, authentification par mot de passe, root, algorithmes, `AllowUsers`, avec test et anti-verrouillage | Cœur | ★★★ | tous |
| SEC-06 | Clés SSH | Ajouter / retirer des clés autorisées par utilisateur, empreintes, dernière utilisation | Cœur | ★★★ | tous |
| SEC-07 | Fail2ban | Jails, seuils, bannir / débannir, historique | Cœur | ★★ | tous |
| SEC-08 | Surveillance des connexions | SSH acceptées/refusées, sudo, nouvelles IP, géolocalisation optionnelle | Cœur | ★★ | tous |
| SEC-09 | Comptes du panel | Existe ; ajouter expiration, revue périodique | Cœur | ★★ | E |
| SEC-10 | MFA TOTP | Existe | Cœur | ★★★ | tous |
| SEC-11 | Passkeys (WebAuthn) | Connexion sans mot de passe ou second facteur | Cœur | ★★★ | tous |
| SEC-12 | SSO (OIDC, SAML) | Connexion via un fournisseur d'identité | Ext. (OIDC en Cœur à terme) | ★★ | E H |
| SEC-13 | Journal d'audit | Infalsifiable (chaîné), filtrable, exportable (syslog, JSON) | Cœur | ★★★ | E H |
| SEC-14 | Coffre de secrets | Secrets chiffrés (enveloppe), rotation de clé maître, références plutôt que valeurs | Cœur | ★★★ | tous |
| SEC-15 | Détection d'anomalies | Pics de connexions, processus inconnus écoutant un port, binaires modifiés, connexions à des heures inhabituelles | Cœur (règles simples) / Ext. (ML) | ★★ | E |
| SEC-16 | Intégrité des fichiers | AIDE ou hachage des binaires et configs sensibles | Ext. | ★ | E |
| SEC-17 | Recommandations | Liste priorisée « faites ceci » avec gain et action (façon Advisor) | Cœur | ★★★ | tous |
| SEC-18 | Configurations sensibles | sysctl, permissions `/etc/shadow`, sudoers, services exposés, mises à jour automatiques | Cœur | ★★ | tous |
| SEC-19 | Conformité (CIS, ANSSI) | Profils de contrôle | Ext. | ★ | E |
| SEC-20 | Antivirus / malware web | ClamAV, détection de webshells | Ext. | ★ | H |

## G. Monitoring et observabilité

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| MON-01 | CPU, RAM, swap, charge | Existe ; ajouter par cœur, steal (VPS), pression (PSI) | Cœur | ★★★ | tous |
| MON-02 | Disques | Par partition, I/O, latence, inodes | Cœur | ★★★ | tous |
| MON-03 | Réseau | Par interface | Cœur | ★★ | tous |
| MON-04 | Températures, ventilateurs | Si capteurs disponibles | Cœur | ★ | P |
| MON-05 | Processus | Historique des gros consommateurs | Cœur | ★★ | tous |
| MON-06 | Conteneurs | Voir CTR-08 | Cœur | ★★ | tous |
| MON-07 | Sites : disponibilité et latence | Existe ; ajouter contenu attendu, depuis plusieurs points | Cœur | ★★★ | tous |
| MON-08 | Bases de données | Voir DB-06 | Cœur | ★★ | E |
| MON-09 | Sondes HTTP / TCP / ping / DNS | Moniteurs configurables, indépendants des sites découverts | Cœur | ★★ | tous |
| MON-10 | Certificats | Existe ; ajouter certificats distants (domaines non hébergés) | Cœur | ★★ | tous |
| MON-11 | Alertes personnalisables | Règles (métrique, seuil, durée, portée), silences, maintenance, escalade | Cœur | ★★★ | tous |
| MON-12 | Incidents | Historique ouvert/résolu, durée, accusé de réception, commentaires, chronologie | Cœur | ★★★ | E H |
| MON-13 | Graphiques | Zoom, superposition, export CSV/PNG | Cœur | ★★ | tous |
| MON-14 | Comparaison entre serveurs | Même métrique sur N serveurs | Cœur | ★★ | E H |
| MON-15 | Rapports périodiques | Hebdo/mensuel par e-mail : disponibilité, incidents, mises à jour, sauvegardes | Cœur | ★★ | E H |
| MON-16 | Pages de statut publiques | Statut public des services | Ext. | ★ | F H |
| MON-17 | Export Prometheus / OpenTelemetry | Exposer ou envoyer les métriques | Ext. | ★★ | E |
| MON-18 | Intégration Netdata / Uptime Kuma | Liens, import d'alertes | Ext. | ★ | P E |
| MON-19 | Journaux centralisés | Recherche multi-sources, rétention | Cœur (simple) / Ext. (Loki) | ★★ | E |
| MON-20 | Notifications push mobiles | Web Push (PWA), ntfy, Gotify | Cœur | ★★★ | tous |

## H. Sauvegardes et restauration

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| BAK-01 | Sauvegardes automatiques | Existe (nuit) | Cœur | ★★★ | tous |
| BAK-02 | Incrémentales dédupliquées | Moteur restic (ou Borg/Kopia) : chiffré, dédupliqué, incrémental | Cœur | ★★★ | tous |
| BAK-03 | Complètes | Existe (tar.gz) ; à conserver comme option simple | Cœur | ★★ | tous |
| BAK-04 | Destinations distantes | S3, SFTP, B2, WebDAV, disque local/USB, autre serveur de la flotte | Cœur | ★★★ | tous |
| BAK-05 | Politiques par ressource | Ce qui est sauvegardé (site, base, volume, dossier, config), quand, où, combien de temps | Cœur | ★★★ | tous |
| BAK-06 | Restauration sélective | Un fichier, un dossier, une base, depuis le panel, vers un autre emplacement par défaut | Cœur | ★★★ | tous |
| BAK-07 | Restauration complète | D'un site entier, d'un serveur sur un nouveau serveur | Cœur | ★★★ | tous |
| BAK-08 | Vérification d'intégrité | Vérification périodique du dépôt (checksums, `restic check`) | Cœur | ★★★ | tous |
| BAK-09 | Test automatique de restauration | Restaurer une base dans un schéma temporaire et vérifier qu'elle se charge ; extraire un échantillon de fichiers | Cœur | ★★ | E H |
| BAK-10 | Rétention configurable | GFS (quotidien, hebdo, mensuel) | Cœur | ★★ | tous |
| BAK-11 | Historique et rapport | Durée, taille, delta, erreurs, alertes | Cœur | ★★ | tous |
| BAK-12 | Instantanés du panel | Exporter / importer la configuration Hostkeep (existe : archive de migration) | Cœur | ★★ | tous |
| BAK-13 | Instantanés de volumes (LVM/ZFS/Btrfs) | Cohérence à chaud | Ext. | ★ | E |

## I. Fichiers

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| FIL-01 | Explorateur | Existe (lecture) | Cœur | ★★★ | tous |
| FIL-02 | Éditeur | Coloration syntaxique, validation (JSON, YAML, conf nginx), diff avant enregistrement, copie de sauvegarde | Cœur | ★★★ | tous |
| FIL-03 | Téléversement | Glisser-déposer, gros fichiers par morceaux | Cœur | ★★★ | tous |
| FIL-04 | Téléchargement | Existe (≤ 50 Mo) ; dossiers en archive | Cœur | ★★ | tous |
| FIL-05 | Permissions et propriétaires | chmod, chown, récursif avec aperçu | Cœur | ★★ | tous |
| FIL-06 | Recherche | Par nom et par contenu, dans une racine autorisée | Cœur | ★★ | tous |
| FIL-07 | Compression / décompression | zip, tar.gz | Cœur | ★★ | tous |
| FIL-08 | Opérations | Créer, renommer, déplacer, copier, corbeille (suppression réversible) | Cœur | ★★★ | tous |
| FIL-09 | Transfert entre serveurs | Copier un fichier/dossier d'un serveur à un autre via les agents | Cœur | ★★ | E H |
| FIL-10 | Accès SFTP pour un client | Créer un accès SFTP chrooté temporaire | Cœur | ★★ | F H |

## J. Automatisation

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| AUT-01 | Scripts enregistrés | Scripts versionnés, paramètres typés, exécution sur un ou N serveurs, **réservés à une permission dédiée** | Cœur | ★★ | E H |
| AUT-02 | Tâches planifiées du panel | Planifier n'importe quelle opération (sauvegarde, mise à jour, redémarrage, script) | Cœur | ★★★ | tous |
| AUT-03 | Workflows | Enchaîner des opérations avec conditions et validation humaine | Cœur (simple) | ★★ | E H |
| AUT-04 | Déclencheurs | Événement (alerte, déploiement, sauvegarde échouée), horaire, webhook entrant | Cœur | ★★ | E |
| AUT-05 | Actions automatiques | « Si le service X tombe, le redémarrer une fois puis alerter » (remédiation bornée) | Cœur | ★★★ | tous |
| AUT-06 | Webhooks sortants | Par type d'événement, signés (HMAC) | Cœur | ★★ | E |
| AUT-07 | API REST publique | Versionnée, documentée (OpenAPI), jetons à portée | Cœur | ★★★ | E H |
| AUT-08 | Notifications | Existe (e-mail, Discord, Telegram, webhook) ; ajouter Slack, Teams, Matrix, ntfy, Gotify, Web Push ; routage par règle | Cœur | ★★★ | tous |
| AUT-09 | Bus d'événements | Tous les changements d'état émis comme événements typés | Cœur | ★★★ | (interne) |
| AUT-10 | Automatisations conditionnelles | Règles « quand / si / alors » | Cœur | ★★ | E |
| AUT-11 | CLI distante | `hostkeep` qui parle à l'API (gestion de flotte depuis un poste) | Cœur | ★★ | E |
| AUT-12 | Fournisseur Terraform / module Ansible | Infrastructure as code | Ext. | ★ | E |

## K. Administration professionnelle

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| ORG-01 | Organisations | Espaces étanches (serveurs, comptes, secrets) | Cœur | ★★ | E H |
| ORG-02 | Équipes | Groupes d'utilisateurs avec droits communs | Cœur | ★★ | E H |
| ORG-03 | Utilisateurs | Existe ; ajouter appartenance multi-organisations | Cœur | ★★★ | tous |
| ORG-04 | Rôles personnalisés | Créer des rôles à partir de permissions | Cœur | ★★ | E H |
| ORG-05 | Permissions granulaires à portée | Permission × portée (organisation, groupe de serveurs, serveur, site, stack) | Cœur | ★★★ | E H |
| ORG-06 | Multi-clients | Clients avec accès restreint à leurs sites ; quotas ; marque blanche légère | Ext. (« Hosting ») | ★★ | H |
| ORG-07 | Historique des actions | Voir SEC-13, filtrable par organisation | Cœur | ★★★ | E H |
| ORG-08 | API publique et jetons | Voir AUT-07 ; jetons personnels et de service, expiration, portée, IP | Cœur | ★★★ | E H |
| ORG-09 | Journalisation | Journaux applicatifs structurés (JSON) | Cœur | ★★ | E |
| ORG-10 | Extensions | Système d'extensions signées | Cœur (mécanisme) | ★★ | tous |
| ORG-11 | Gestion centralisée | Opérations groupées sur N serveurs (mettre à jour 20 serveurs avec rapport) | Cœur | ★★★ | E H |
| ORG-12 | Validation à quatre yeux | Une action critique doit être approuvée par un second compte | Cœur (option) | ★★ | E |
| ORG-13 | Facturation, revendeurs | — | Hors (intégrations WHMCS/autres via API) | — | — |

## L. Intelligence artificielle (facultative)

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| AI-01 | Fournisseurs multiples | Abstraction : API compatibles (Anthropic, OpenAI, Mistral…), modèle local (Ollama, llama.cpp) ; désactivée par défaut | Ext. officielle | ★★ | tous |
| AI-02 | Analyse de l'état | Résumé en langage clair de la santé d'un serveur | Ext. | ★★ | P F |
| AI-03 | Diagnostic | « Pourquoi mon site est lent / renvoie 502 ? » à partir des données collectées | Ext. | ★★ | tous |
| AI-04 | Processus anormaux | Explication d'un processus inconnu ou gourmand | Ext. | ★ | tous |
| AI-05 | Analyse de journaux | Regrouper, expliquer, relier à un incident | Ext. | ★★ | tous |
| AI-06 | Explication d'erreurs | Bouton « Expliquer » sur une erreur de tâche | Ext. | ★★ | P F |
| AI-07 | Propositions de corrections | Sous forme **d'opérations typées** du catalogue, jamais de commandes shell libres | Ext. | ★★ | tous |
| AI-08 | Plans de maintenance | Préparer une suite d'opérations soumise à validation | Ext. | ★ | E |
| AI-09 | Exécution après validation | Uniquement via le moteur d'opérations, avec les permissions de l'utilisateur, aperçu et confirmation | Ext. | ★ | tous |
| AI-10 | Serveur MCP | Exposer les opérations Hostkeep à des assistants externes, avec jetons à portée | Ext. | ★ | E |

## M. Interface utilisateur

| ID | Fonctionnalité | Description | Place | Valeur | Public |
|---|---|---|---|---|---|
| UI-01 | Dashboard personnalisable | Widgets choisis et ordonnés, par utilisateur | Cœur | ★★ | tous |
| UI-02 | Responsive | Existe | Cœur | ★★★ | tous |
| UI-03 | Expérience mobile complète | Toutes les opérations courantes au téléphone | Cœur | ★★★ | tous |
| UI-04 | PWA | Existe ; ajouter Web Push | Cœur | ★★★ | tous |
| UI-05 | Thèmes clair / sombre | Existe | Cœur | ★★ | tous |
| UI-06 | Recherche universelle | Existe ; étendre aux ressources (serveurs, sites, conteneurs) et aux opérations | Cœur | ★★★ | tous |
| UI-07 | Navigation multi-serveurs | Sélecteur de serveur / vue flotte | Cœur | ★★★ | tous |
| UI-08 | Centre de notifications | Liste persistante, non lues, filtres | Cœur | ★★ | tous |
| UI-09 | Assistant de premier démarrage | Checklist : 2FA, sauvegarde externe, alertes, pare-feu, mises à jour auto | Cœur | ★★★ | P F |
| UI-10 | Documentation contextuelle | Aide « ? » par écran, liens vers la doc, explication de chaque opération | Cœur | ★★ | tous |
| UI-11 | Traductions | Infrastructure i18n ; français et anglais d'abord | Cœur | ★★★ | tous |
| UI-12 | Préférences | Langue, fuseau, unités, page d'accueil, densité | Cœur | ★ | tous |
| UI-13 | Progression des opérations | Barre de tâches persistante, étapes, temps restant, annulation | Cœur | ★★★ | tous |
| UI-14 | Historique des actions | Par ressource (« ce qui a été fait sur ce site ») | Cœur | ★★ | tous |
| UI-15 | Aperçu avant exécution | Pour chaque opération : ce qui va changer (diff, fichiers, services touchés), réversibilité | Cœur | ★★★ | tous |
| UI-16 | Accessibilité | WCAG 2.2 AA mesurée | Cœur | ★★★ | tous |
| UI-17 | Terminal web | Shell dans le navigateur | Ext. désactivée par défaut, permission dédiée, enregistrement de session | ★ | F E |
