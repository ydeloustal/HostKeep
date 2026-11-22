# Analyse concurrentielle

> **Sources :** documentations officielles et dépôts publics des projets (liens en fin de section), connaissances publiques arrêtées à mi-2026.
> **Statut :** aucune installation de test n'a été faite pour cette analyse. Les licences, éditions payantes et fonctionnalités récentes évoluent vite : chaque ligne marquée **(à revérifier)** doit être confirmée sur la source officielle avant toute communication publique.
> **But :** comprendre ce que chaque produit fait bien ou mal, pour décider ce que Hostkeep doit faire **à sa manière** — pas copier.

---

## 1. Carte du marché

Les « panels » couvrent des besoins très différents. Les placer sur deux axes évite de comparer l'incomparable :

| | **Hôte Linux** (paquets, services, fichiers, sécurité) | **Conteneurs / PaaS** (Docker, déploiements) |
|---|---|---|
| **Mono-serveur** | Cockpit*, Webmin, HestiaCP, CloudPanel, aaPanel, **Hostkeep (aujourd'hui)** | 1Panel (v1), CapRover |
| **Multi-serveurs** | Cockpit (via SSH), Virtualmin, ISPConfig, Plesk | Coolify, Portainer, Dokploy, Komodo |
| **Observation seule** | Netdata, Beszel, Uptime Kuma | — |

\* Cockpit gère aussi Podman et des VM, mais reste centré sur l'hôte.

**Constat :** aucun produit open source ne couvre correctement **à la fois** l'administration de l'hôte, l'hébergement web classique (PHP/Nginx/Apache), les conteneurs, le multi-serveurs et une expérience mobile. C'est l'espace que Hostkeep peut viser — à condition de ne pas sacrifier la sécurité et la légèreté qui le distinguent déjà.

---

## 2. Fiches par produit

### 2.1 1Panel
- **Quoi :** panel de serveur moderne, très complet, orienté « app store » de conteneurs. Édité par FIT2CLOUD.
- **Architecture :** backend Go (Gin, GORM, SQLite), frontend Vue 3 ; binaire unique + service systemd. Les sites web sont servis par **OpenResty dans un conteneur** ; bases (MySQL, PostgreSQL, Redis…) installées comme applications Docker. **(à revérifier)** : la v2 introduit la gestion de plusieurs nœuds, en partie réservée à l'édition Pro.
- **Licence :** GPL-3.0 (édition communautaire) ; édition Pro payante **(à revérifier)**.
- **Fonctionnalités :** sites (statique, PHP, reverse proxy), certificats, app store (centaines d'apps), conteneurs/images/réseaux/volumes/compose, bases de données, gestionnaire de fichiers avec éditeur, terminal web, cron, pare-feu (ufw/firewalld), Fail2ban, réglages SSH, sauvegardes vers S3/SFTP/WebDAV/clouds, instantanés (snapshots) du panel, journaux, gestion de modèles IA locaux (Ollama) et serveur MCP **(à revérifier)**.
- **Points forts :** étendue fonctionnelle, interface soignée, installation simple, cohérence « tout en conteneur », sauvegardes multi-destinations.
- **Faiblesses :** les sites passent par un OpenResty conteneurisé (cohabitation difficile avec un Nginx/Apache existant, qui doit souvent être abandonné) ; panel très puissant (terminal root, fichiers root) donc surface d'attaque large ; écosystème et documentation majoritairement en chinois historiquement ; fonctions avancées en Pro.
- **Bonnes idées pour Hostkeep :** catalogue d'applications basé sur des modèles Compose versionnés ; « instantané » du panel ; sauvegardes multi-destinations configurables par élément (site, base) ; gestion des versions PHP par site.
- **À ne pas reprendre :** prendre le contrôle exclusif du serveur web ; terminal root dans le navigateur par défaut.
- Sources : https://github.com/1Panel-dev/1Panel · https://1panel.pro/docs

### 2.2 Coolify
- **Quoi :** PaaS auto-hébergé (alternative à Heroku / Netlify / Vercel).
- **Architecture :** Laravel (PHP) + Livewire, PostgreSQL, Redis ; tourne lui-même dans Docker. **Sans agent** : pilote chaque serveur **par SSH** et y exécute des commandes Docker. Proxy Traefik (ou Caddy) géré par Coolify.
- **Licence :** Apache-2.0 ; offre cloud payante.
- **Fonctionnalités :** déploiement depuis Git (application GitHub, GitLab, Bitbucket, webhooks), builds Nixpacks / Dockerfile / Compose, déploiements de prévisualisation par pull request, rollback, variables d'environnement et secrets, services en un clic (~200+ modèles), bases de données avec sauvegardes S3, multi-serveurs et Docker Swarm, équipes, API avec jetons, notifications (e-mail, Discord, Telegram, Slack…), terminal.
- **Points forts :** expérience « git push → en ligne » excellente ; multi-serveurs sans rien installer d'autre que Docker ; communauté très active.
- **Faiblesses :** tout doit être conteneurisé ; ne gère pas l'hôte (paquets, pare-feu, utilisateurs, sécurité système) ; consommation notable (recommandation de l'ordre de 2 Go de RAM pour le serveur Coolify) ; la clé SSH root du serveur central donne un accès total à toute la flotte ; stabilité de la v4 souvent discutée par la communauté.
- **Bonnes idées :** prévisualisations par PR ; modèle « Projet → Environnement → Ressource » ; webhooks de déploiement ; sauvegardes de bases planifiées vers S3 ; notifications par événement typé.
- **À ne pas reprendre :** un unique secret SSH root qui ouvre toute la flotte.
- Sources : https://github.com/coollabsio/coolify · https://coolify.io/docs

### 2.3 Portainer
- **Quoi :** interface de gestion de conteneurs (Docker, Swarm, Kubernetes, Podman).
- **Architecture :** serveur Go + interface web ; **agents** : Portainer Agent (connexion entrante depuis le serveur) et **Edge Agent** (connexion **sortante** de l'agent vers le serveur, pour les sites distants derrière NAT).
- **Licence :** Community Edition sous licence zlib ; Business Edition payante (RBAC fin, SSO avancé, audit…) **(à revérifier pour la répartition exacte)**.
- **Fonctionnalités :** conteneurs, images, volumes, réseaux, stacks Compose (y compris GitOps : déploiement auto depuis un dépôt), registres, modèles d'applications, environnements multiples, équipes, journaux et statistiques par conteneur, console.
- **Points forts :** référence de la gestion de conteneurs ; modèle Edge Agent (sortant) très adapté aux serveurs sans IP publique.
- **Faiblesses :** rien sur l'hôte ni l'hébergement web ; fonctions d'entreprise payantes ; interface dense pour un débutant.
- **Bonnes idées :** **Edge Agent sortant** ; stacks liées à un dépôt Git avec mise à jour automatique ; modèles d'applications au format ouvert.
- Sources : https://github.com/portainer/portainer · https://docs.portainer.io

### 2.4 Cockpit
- **Quoi :** console web d'administration de l'hôte, intégrée à RHEL/Fedora, disponible sur Debian/Ubuntu.
- **Architecture :** `cockpit-ws` (C) activé par socket systemd, `cockpit-bridge` lancé **sous l'utilisateur connecté** ; utilise les API système (D-Bus, systemd, udisks, NetworkManager, PackageKit). Authentification PAM : ce sont les **comptes Linux** ; l'élévation passe par sudo/polkit. Multi-hôte via SSH. Modules en JavaScript (React/PatternFly).
- **Licence :** LGPL-2.1.
- **Fonctionnalités :** services, journaux, stockage (RAID, LVM, chiffrement), réseau, mises à jour, comptes, terminal, conteneurs Podman, machines virtuelles (libvirt), SELinux, métriques (historique via PCP, optionnel).
- **Points forts :** zéro processus au repos ; s'appuie sur les API officielles plutôt que de parser des commandes ; respect natif des droits Linux ; extensible par paquets.
- **Faiblesses :** aucune notion de site web, certificat, base de données applicative ou sauvegarde ; historique de métriques optionnel ; pas de comptes propres au panel ni de RBAC applicatif ; mobile limité.
- **Bonnes idées :** **activation par socket** (pas de démon au repos) ; utiliser D-Bus/systemd plutôt que parser `systemctl` ; modules livrés comme paquets séparés ; le « bridge » qui s'exécute avec les droits de l'utilisateur.
- Sources : https://github.com/cockpit-project/cockpit · https://cockpit-project.org/guide/latest/

### 2.5 Webmin (et Virtualmin)
- **Quoi :** l'ancêtre (depuis 1997) : un module pour presque chaque service Unix. Virtualmin ajoute l'hébergement multi-domaines.
- **Architecture :** serveur Perl autonome (miniserv) tournant en root, modules Perl qui lisent/écrivent directement les fichiers de configuration.
- **Licence :** BSD-3 (Webmin) ; Virtualmin GPL + édition Pro **(à revérifier)**.
- **Fonctionnalités :** Apache, Nginx (module), BIND, Postfix/Dovecot, Samba, utilisateurs/groupes, cron, pare-feu, paquets, quotas, MySQL/PostgreSQL, sauvegardes, clusters ; Virtualmin : domaines virtuels, mail, DNS, bases, SSL, scripts d'installation, revendeurs.
- **Points forts :** exhaustivité inégalée ; édite les vrais fichiers de configuration (pas de format propriétaire) ; multi-distributions.
- **Faiblesses :** UX datée malgré le thème Authentic ; processus web **root** ; historique de sécurité (dont la porte dérobée de 2019 introduite via la chaîne de build, CVE-2019-15107) ; complexité.
- **Bonnes idées :** éditer les fichiers de configuration natifs en respectant leur format ; couverture multi-distributions.
- **Contre-exemple utile :** l'incident de 2019 montre pourquoi **les releases doivent être signées et reproductibles** (voir S3 de l'audit).
- Sources : https://github.com/webmin/webmin · https://www.virtualmin.com/docs/

### 2.6 Netdata
- **Quoi :** observabilité temps réel (métriques à la seconde).
- **Architecture :** agent en C (+ collecteurs Go), base de séries temporelles embarquée, streaming « parent/enfant » pour centraliser ; Netdata Cloud (SaaS) pour la vue multi-nœuds. **(à revérifier)** l'interface du tableau de bord v2 est distribuée sous une licence non libre, l'agent restant GPL-3.0+.
- **Fonctionnalités :** des centaines d'intégrations auto-détectées, alertes prêtes à l'emploi, détection d'anomalies par apprentissage automatique local, corrélations, exports.
- **Points forts :** zéro configuration, granularité à la seconde, alertes pertinentes par défaut.
- **Faiblesses :** observation seulement (aucune action) ; consommation non négligeable sur petits VPS ; fonctions multi-nœuds poussées vers le cloud.
- **Bonnes idées :** **alertes livrées avec des seuils par défaut sensés et ajustables** ; détection d'anomalies locale et légère ; rétention par paliers (haute résolution récente, agrégats ensuite).
- **Positionnement Hostkeep :** ne pas concurrencer Netdata sur la finesse des métriques ; **s'intégrer** (lien, import d'alertes) via une extension.
- Sources : https://github.com/netdata/netdata · https://learn.netdata.cloud

### 2.7 HestiaCP
- **Quoi :** panel d'hébergement classique (fork de VestaCP) : web, mail, DNS, bases, FTP.
- **Architecture :** commandes Bash `v-*` exécutées en root (via sudo) + interface PHP ; prend le contrôle d'un serveur **neuf**.
- **Licence :** GPL-3.0.
- **Fonctionnalités :** Nginx (+ Apache) / PHP-FPM multi-versions, Let's Encrypt, DNS (BIND, cluster DNS), mail complet (Exim, Dovecot, antispam, antivirus, webmail), MariaDB/PostgreSQL, FTP, utilisateurs et paquets d'hébergement, installeurs rapides (WordPress…), sauvegardes, pare-feu, Fail2ban.
- **Points forts :** couverture « hébergeur » complète et gratuite ; API ; CLI scriptable et lisible.
- **Faiblesses :** exige un serveur vierge ; mono-serveur ; pas de conteneurs ; interface classique peu adaptée au mobile.
- **Bonnes idées :** **chaque action = une commande CLI documentée**, appelable par l'interface, l'API ou un script (Hostkeep a déjà le germe avec `hostkeep-action`) ; modèles de configuration par type de site ; paquets d'hébergement (quotas).
- Sources : https://github.com/hestiacp/hestiacp · https://hestiacp.com/docs/

### 2.8 Plesk
- **Quoi :** panel commercial de référence pour les hébergeurs, Linux et Windows.
- **Architecture :** propriétaire ; services root, extensions (catalogue), API REST et XML-RPC, CLI.
- **Licence :** commerciale (abonnement).
- **Fonctionnalités :** domaines, mail, DNS, bases, WordPress Toolkit, Git, Node.js/Python/Ruby, Docker (extension), multi-tenant (clients, revendeurs, abonnements), sécurité (Advisor, intégrations Imunify), sauvegardes planifiées et distantes, intégration facturation.
- **Points forts :** maturité, multi-tenant, WordPress Toolkit, écosystème d'extensions, support.
- **Faiblesses :** coût, lourdeur, code fermé, prend le contrôle du serveur.
- **Bonnes idées :** **« Advisor »** — une page de recommandations classées avec gain estimé et action en un clic (Hostkeep a déjà la base avec l'audit actionnable) ; modèle **abonnement / client / revendeur** pour une future offre multi-clients.
- Sources : https://docs.plesk.com

### 2.9 CloudPanel
- **Quoi :** panel gratuit orienté performance pour sites PHP, Node.js, Python et statiques.
- **Architecture :** application PHP ; Nginx, PHP-FPM, MySQL/MariaDB, Varnish ; images prêtes pour les principaux clouds. Exige un serveur neuf Debian/Ubuntu.
- **Licence :** gratuite mais **non open source** au sens OSI **(à revérifier)**.
- **Fonctionnalités :** sites par type (PHP avec versions, Node.js, Python, statique, reverse proxy), modèles Nginx par application (WordPress, Laravel…), utilisateurs de site (SSH/SFTP isolés), bases, Let's Encrypt, sauvegardes distantes (via rclone), Varnish, règles de pare-feu, journaux, CLI `clpctl`.
- **Points forts :** rapide, simple, modèles de vhost par framework, isolement par utilisateur de site.
- **Faiblesses :** mono-serveur, prend le contrôle du serveur, pas de conteneurs, code fermé.
- **Bonnes idées :** **un utilisateur Linux par site** (isolation PHP-FPM, SFTP chrooté) ; **modèles de vhost par framework** ; CLI miroir de l'interface.
- Sources : https://www.cloudpanel.io/docs/

### 2.10 Autres projets pertinents

| Projet | Licence | En bref | Idée à retenir |
|---|---|---|---|
| **Komodo** | GPL-3.0 | Rust ; « Core » + agent « Periphery » sur chaque serveur ; builds, déploiements, stacks Compose, procédures | Architecture **cœur + agent léger** très proche de la cible ; procédures = workflows simples |
| **Beszel** | MIT | Go ; « hub » + agent minuscule ; métriques serveur et conteneurs, alertes | Agent de quelques Mo de RAM ; **clé publique du hub autorisée côté agent** |
| **Dokploy** | Apache-2.0 avec conditions sur certaines fonctions **(à revérifier)** | Alternative à Coolify sur Docker Swarm, multi-serveurs | Déploiement Compose et Swarm simple |
| **CapRover** | Apache-2.0 | PaaS sur Docker Swarm, applis en un clic | Simplicité du « one-click app » |
| **Uptime Kuma** | MIT | Supervision de disponibilité (HTTP, TCP, DNS, ping…), pages de statut | **Pages de statut publiques** ; nombreux types de sondes ; Hostkeep s'y intègre déjà (ping) |
| **ISPConfig** | BSD-3 | Panel d'hébergement **multi-serveurs maître/esclaves** (web, mail, DNS, bases) | Modèle maître/esclaves par **file de modifications** répliquée, éprouvé depuis 15 ans |
| **aaPanel / BT Panel** | Propriétaire, freemium | Panel très populaire en Asie | Montre l'attente d'un app store ; historique de sécurité à étudier comme contre-exemple **(à revérifier)** |

---

## 3. Matrice de synthèse

Légende : ●● fort · ● présent · ○ partiel · — absent. Évaluation qualitative, à revérifier.

| Domaine | Hostkeep 2.9 | 1Panel | Coolify | Portainer | Cockpit | Webmin/Virtualmin | Netdata | HestiaCP | Plesk | CloudPanel |
|---|---|---|---|---|---|---|---|---|---|---|
| Admin hôte (services, paquets) | ● | ● | — | — | ●● | ●● | — | ● | ● | ○ |
| Sécurité hôte (audit, pare-feu, SSH) | ● (audit) | ● | — | — | ● | ● | ○ | ● | ●● | ○ |
| Sites web classiques (PHP, vhosts) | ○ (lecture) | ● | ○ | — | — | ●● | — | ●● | ●● | ●● |
| Coexistence avec config existante | ●● | ○ | ○ | ●● | ●● | ●● | ●● | — | — | — |
| Conteneurs / Compose | — | ●● | ●● | ●● | ● (Podman) | ○ | ○ (métriques) | — | ● | — |
| Déploiement Git | — | ○ | ●● | ● | — | — | — | — | ● | — |
| Bases de données (gestion) | ○ (lecture) | ● | ● | — | — | ● | ○ | ● | ●● | ● |
| Sauvegardes + externe | ● | ●● | ● | ○ | — | ● | — | ● | ●● | ● |
| Monitoring + historique | ● | ● | ○ | ○ | ○ | ○ | ●● | ○ | ● | ○ |
| Alertes multi-canaux | ● | ● | ●● | ○ | — | ○ | ●● | ○ | ● | ○ |
| Multi-serveurs | — | ○ | ●● | ●● | ● | ● | ● | ○ | ○ | — |
| Équipes / RBAC fin | ○ | ○ | ● | ● (BE) | — (PAM) | ● | ○ | ● | ●● | ○ |
| API publique | — | ● | ● | ●● | ● (D-Bus) | ● | ● | ● | ●● | ○ (CLI) |
| Mobile / PWA | ●● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| Légèreté | ●● | ● | ○ | ● | ●● | ● | ○ | ● | ○ | ● |
| Modèle « web sans root » | ●● | — | ○ | ○ | ●● | — | ● | ○ | — | ○ |

---

## 4. Enseignements

### 4.1 Ce que le marché fait tous très bien (le minimum attendu)
1. **Créer** un site / une base / un conteneur, pas seulement les observer.
2. Gestionnaire de fichiers **avec édition** et upload.
3. Pare-feu, SSH, utilisateurs en quelques clics.
4. Sauvegardes planifiées **par ressource** vers plusieurs destinations.
5. Une API et des jetons.

### 4.2 Les faiblesses récurrentes (l'opportunité de Hostkeep)
1. **Panels qui exigent un serveur vierge** (HestiaCP, CloudPanel, Plesk, 1Panel pour le web) → Hostkeep **cohabite** déjà avec l'existant : c'est un avantage rare à cultiver.
2. **Processus web root** ou terminal root par défaut (Webmin, 1Panel, Coolify via SSH root) → le modèle Hostkeep (web non privilégié + opérations typées) est un différenciateur de sécurité **réel**.
3. **Interfaces pensées pour le bureau** → Hostkeep est le seul réellement mobile-first.
4. **Séparation hôte / conteneurs** : on installe Cockpit **et** Portainer **et** Coolify **et** Netdata. Une plateforme unifiée et cohérente a de la valeur.
5. **Fonctions essentielles derrière une édition payante** (multi-nœuds, RBAC, SSO) → rester entièrement libre sur le cœur est un argument.

### 4.3 Approches techniques à retenir
| Problème | Meilleure approche observée | Chez qui |
|---|---|---|
| Piloter N serveurs sans exposer de port | Agent qui **se connecte en sortie** au serveur central | Portainer Edge, Netdata, Komodo (option), Beszel |
| Piloter sans installer d'agent | SSH depuis le central | Coolify, Cockpit, Ansible |
| Ne pas consommer au repos | Activation par socket systemd | Cockpit |
| Interagir proprement avec l'hôte | API D-Bus/systemd plutôt que parser des commandes | Cockpit |
| Actions reproductibles et scriptables | Une commande CLI par action, réutilisée par l'UI et l'API | HestiaCP, CloudPanel |
| Multi-serveurs d'hébergement fiable | File de modifications répliquée vers des nœuds | ISPConfig |
| Applications prêtes à l'emploi | Modèles Compose versionnés dans un dépôt de catalogue | 1Panel, Coolify, Portainer |
| Alerting utile sans configuration | Seuils par défaut + ajustement | Netdata |

### 4.4 Ce qu'il ne faut pas copier
- Le terminal root dans le navigateur comme fonction centrale (le garder optionnel, désactivé par défaut, extension).
- La prise de contrôle exclusive du serveur web ou l'exigence d'un serveur vierge.
- Les fonctions de sécurité ou de multi-serveurs réservées à une édition payante.
- La conteneurisation forcée de tout (les sites PHP natifs restent le cas majoritaire des petits VPS).
- Le mail complet (Exim/Dovecot/antispam) dans le cœur : énorme coût de maintenance et de réputation d'IP, à réserver à une extension, voire à ne pas faire.

---

## 5. Positionnement proposé

> **Hostkeep : administrer ses serveurs Linux — hôte, sites, conteneurs et sauvegardes — depuis une seule interface, sans jamais donner de shell root au web, en cohabitant avec ce qui existe, et jusque sur son téléphone.**

Différenciateurs à défendre (dans l'ordre) :
1. **Sécurité par construction** : web non privilégié, opérations typées et vérifiées, agent au moindre privilège, releases signées.
2. **Cohabitation** : s'installe sur un serveur en production sans rien casser, lit la configuration existante, propose des changements réversibles.
3. **Unifié** : hôte + web + conteneurs + bases + sauvegardes + supervision.
4. **Mobile-first et simple** : chaque opération courante faisable au téléphone, avec aperçu avant exécution.
5. **Léger** : quelques dizaines de Mo, rien au repos.
