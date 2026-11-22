# Feuille de route produit

> Ordre de réalisation proposé, justifié par les dépendances techniques. Les durées sont des **ordres de grandeur pour un développeur à temps plein** ; elles servent à comparer les phases, pas à promettre des dates.
> Références : écarts dans `FEATURE_GAP_ANALYSIS.md`, architecture dans `TARGET_ARCHITECTURE.md`, sécurité dans `SECURITY_ROADMAP.md`.
>
> **Mis à jour pour la 2.10.1** : la Phase 0 est livrée. Les phases 1, 2 et 6 intègrent les exigences d'un **produit public distribué à grande échelle** (`TARGET_ARCHITECTURE.md` §15, décisions D-17 à D-22) ; ces ajouts sont marqués 🌍.

---

## Pourquoi cet ordre (et pas celui de l'exemple)

L'exemple proposait « consolidation → infrastructure (multi-serveurs) → hébergement → supervision → pro → IA ». L'analyse conduit à **trois ajustements** :

1. **Une phase « fondations » s'intercale avant toute nouvelle fonctionnalité.** Le catalogue d'opérations, la file de jobs en base, les migrations versionnées et l'i18n coûtent peu aujourd'hui (21 actions, ~6 écrans) et beaucoup plus après chaque ajout. Construire Docker ou le pare-feu sur le modèle actuel, puis tout réécrire pour le multi-serveurs, serait le pire scénario.
2. **Le multi-serveurs arrive tôt, mais en deux temps** : d'abord l'agent **local** (gain de sécurité immédiat : PHP n'appelle plus `sudo`), puis les agents distants. Toutes les fonctionnalités suivantes ciblent alors « un serveur » et non « ce serveur ».
3. **Les organisations / équipes sont préparées dans le modèle de données dès la Phase 1, mais livrées plus tard.** Les ajouter à la fin imposerait de toucher toutes les tables et requêtes.

> ⚑ **Décision à valider (D-12)** : cet ordre retarde les fonctionnalités visibles (pare-feu, Docker) d'environ 2 à 3 mois. L'alternative « fonctionnalités d'abord » est décrite en fin de document.

---

## Vue d'ensemble

| Phase | Nom | Version | Durée indicative | Résultat principal |
|---|---|---|---|---|
| 0 ✅ | Sécurisation immédiate | 2.10.0 / 2.10.1 | livrée le 22/11/2026 | Failles corrigées, socle de tests, mécanisme de releases signées |
| 1 | Fondations | 3.0 | 8–10 semaines | Même périmètre, base saine : catalogue d'opérations, jobs, migrations, API v1, i18n FR/EN, passkeys, archives signées, documentation publique |
| 2 | Agent et multi-serveurs | 3.1 → 3.2 | 10–12 semaines | Agent local puis distants, vue flotte, API publique (lecture et opérations courantes), dépôt APT, image Docker du central, audit externe |
| 3 | Administration de l'hôte | 3.3 | 6–8 semaines | Pare-feu, SSH, utilisateurs, services, tâches planifiées, mises à jour avancées |
| 4 | Hébergement, conteneurs, bases | 3.4 → 3.6 | 12–16 semaines | Création de sites, PHP par site, Docker/Compose, déploiement Git, bases, fichiers en écriture, sauvegardes restic |
| 5 | Supervision avancée | 3.7 | 6–8 semaines | Alertes configurables, incidents, Web Push, rapports, comparaison |
| 6 | Administration professionnelle | 4.0 | 8–10 semaines | Interface des organisations et équipes, rôles personnalisés, API publique complète, extensions (niveau 1–2) |
| 7 | Automatisation et IA | 4.x | 8+ semaines | Workflows, remédiation, extension IA |

---

## Phase 0 — Sécurisation immédiate (2.10) ✅ livrée

> **Bilan :** livrée en 2.10.0 et 2.10.1. Fait : S1, S2, S4, S5, B1, B2, mécanisme S3, B9 et B10 (trouvés en installant pour de vrai), `SECURITY.md`, `CONTRIBUTING.md`, CI PHP 8.1–8.4, gitleaks, tests PHP et helper, tests d'intégration et d'attaque. **Écarts avec le plan :** tests du helper en bash plutôt qu'en bats ; PHPUnit remplacé par de petits scripts PHP (PHPUnit arrive en Phase 1 avec Composer, D-05) ; signature par tags SSH plutôt qu'archives minisign ; mesures de référence non faites (besoin d'un vrai VPS). **Reporté en Phase 1 :** rclone non root, S7, S8, tests d'intégration dans le CI. **Actions du mainteneur restantes :** clé de signature, protection de `main`, signalement privé (`MASTER_PLAN.md` §7).

**Fonctionnalités / travaux**
- Correctifs S1 à S5, B1, B2 (voir `SECURITY_ROADMAP.md` §2).
- Releases signées sur tags + vérification dans `self-update`.
- `SECURITY.md`, protection de la branche `main`, gitleaks.
- CI : matrice PHP 8.1 → 8.4 ; tests **bats** du helper (`validate()` de chaque verbe) ; premiers tests PHPUnit (validateurs d'`ACTIONS`, RBAC, TOTP, `secret_encrypt`).
- Versionner `tools/mock.py` (serveur de démo) ; sortir `admin-dashboard/` de la copie de travail.

| | |
|---|---|
| **Prérequis** | Aucun |
| **Dépendances** | Aucune |
| **Difficulté** | Faible |
| **Risques** | Casser la mise à jour des instances existantes en passant aux tags → prévoir une transition (la 2.10 sait encore se mettre à jour depuis la branche, puis bascule) |
| **Critères de validation** | Tests de non-régression de chaque correctif ; CI verte sur 4 versions de PHP ; une release modifiée est refusée ; aucune notification en double sur 24 h de test |
| **Résultat attendu** | Plus aucune faille connue de niveau moyen ou plus ; un filet de tests sur la partie la plus critique (helper) |

---

## Phase 1 — Fondations (3.0)

**Fonctionnalités / travaux**
- **Migrations versionnées** (`bin/migrate`, `schema_migrations`) ; colonnes préparatoires `org_id` / `server_id` avec valeurs par défaut.
- 🌍 **Modèle multi-organisations dès maintenant** : organisation « Default », `role_bindings` à portée, réglages à trois niveaux (instance, organisation, serveur) ; **tests d'accès croisé** générés pour chaque route, même si l'interface des organisations n'arrive qu'en Phase 6.
- 🌍 **Distribution** : archives de version signées ; l'installateur en une ligne et la mise à jour vérifient la signature **avant** d'exécuter quoi que ce soit (D-11).
- 🌍 **Langue et documentation** : code, API et doc de référence en anglais, interface et doc utilisateur en français **et** anglais (D-18) ; site de documentation versionné (installation, guides débutants, référence) ; politique de versions et de support publiée (D-19) ; DCO, code de conduite, modèles de tickets (D-20).
- **Reports de la Phase 0** : rclone sous un utilisateur non root ; S7 (dumps via le helper) ; S8 (`base_url` sans repli `HTTP_HOST`, échecs 2FA comptés par compte) ; B11 ; tests d'intégration (`tests/integration/`) dans le CI.
- **Restructuration PHP** : autoload PSR-4, routeur + middlewares, contrôleurs par module ; `api.php` conservé comme façade de compatibilité le temps de la transition.
- **Catalogue d'opérations** : les 21 verbes actuels décrits une fois (paramètres, permission, risque, confirmation, aperçu) ; génération des validations PHP et de la liste blanche du front ; le helper Bash reste l'exécuteur.
- **Jobs en base** (`jobs`, `job_logs`) + **worker** systemd ; centre de tâches persistant dans l'interface (UI-13).
- **Événements** (table `events`, outbox) et réécriture des notifications dessus (clés stables).
- **API `/api/v1`** documentée OpenAPI (utilisée par le front) ; jetons personnels (portée limitée, Super Admin seulement dans un premier temps).
- **i18n** : extraction des chaînes (PHP, JS, messages du helper en codes), français + anglais.
- **Sécurité** : passkeys (WebAuthn), ré-authentification pour `critical`, audit chaîné, secrets par enveloppe, dumps via helper.
- **Front** : découpage de `app.js` en modules ES, composants partagés (dialogue d'opération avec aperçu et niveau de risque).
- **Qualité** : PHPStan, Semgrep, PHPUnit (≥ 60 % des lignes de `src/Domain` et `src/Security`), Playwright sur `mock.py` + axe-core, ZAP baseline, test d'installation en conteneur Debian 12/13 et Ubuntu 22.04/24.04.
- **Monitoring** : métriques par partition (B5), sondes de sites parallèles (B4).

| | |
|---|---|
| **Prérequis** | Phase 0 |
| **Dépendances** | Décisions D-02, D-05, D-08, D-12 validées |
| **Difficulté** | Moyenne à élevée (beaucoup de code touché, peu de visible) |
| **Risques** | Régressions pendant la restructuration → avancer module par module derrière les tests E2E ; tentation d'en profiter pour ajouter des fonctionnalités → interdite dans cette phase |
| **Critères de validation** | Toutes les fonctionnalités de la 2.10 fonctionnent à l'identique (suite E2E) ; ajouter une opération = 1 fichier de définition + 1 implémentation + tests ; interface entièrement disponible en anglais ; connexion par passkey ; `hostkeep audit verify` détecte une altération |
| **Résultat attendu** | Une base où chaque fonctionnalité future coûte moins cher et naît sécurisée, traduite, testée et accessible par l'API |

---

## Phase 2 — Agent et multi-serveurs (3.1 → 3.2)

**3.1 — Agent local**
- Agent Go (`hostkeep-agent` non root + `hostkeep-exec` root activé par socket) installé avec le central.
- Le PHP **n'appelle plus `sudo`** : il enfile des jobs que l'agent local exécute ; `hostkeep-exec` délègue au helper Bash existant.
- La collecte migre du collecteur root vers l'agent ; le worker fait les calculs (alertes, prévisions).
- PHP-FPM durci : `NoNewPrivileges`, `ProtectSystem=strict`.

**3.2 — Agents distants**
- Enrôlement (jeton unique, CSR, CA interne), mTLS, requêtes signées, politique locale.
- Transport par sondage HTTPS (voir D-04).
- Vue flotte (liste, état, étiquettes, groupes), sélecteur de serveur, toutes les pages existantes scopées par serveur.
- Opérations groupées (ex. « mettre à jour les paquets sur le groupe *prod* », en série, arrêt au premier échec).
- Mise à jour des agents par vagues avec retour automatique.
- Script d'installation d'agent signé ; Debian/Ubuntu amd64 + arm64.
- 🌍 **API publique (D-22)** : OpenAPI publiée, jetons à portée pour la lecture et les opérations `routine`/`change`.
- 🌍 **Distribution** : dépôt APT signé (agent puis central) ; image Docker du central (D-17).
- 🌍 **Sauvegarde et reprise du central** (base, clé maître, CA des agents), procédure testée.
- 🌍 **Audit de sécurité externe avant la publication de la 3.2** (le central devient la clé de toute la flotte).

| | |
|---|---|
| **Prérequis** | Phase 1 (catalogue, jobs, `server_id`) |
| **Dépendances** | D-03 (Go), D-04 (transport) |
| **Difficulté** | Élevée |
| **Risques** | Nouveau langage dans le projet ; sécurité du protocole ; compatibilité des installations existantes (migration automatique vers « central + agent local ») |
| **Critères de validation** | Une instance 2.x se met à jour en 3.1 sans intervention ; 20 serveurs pilotés depuis un central pendant 7 jours sans perte de métriques > 1 % ; un central compromis (simulé) ne peut pas exécuter une opération hors catalogue ni outrepasser la politique locale ; agent ≤ 25 Mo RSS |
| **Résultat attendu** | Hostkeep gère une flotte, avec un modèle de sécurité plus strict qu'en mono-serveur aujourd'hui |

---

## Phase 3 — Administration de l'hôte (3.3)

**Fonctionnalités** (IDs du catalogue)
- NET-02 + NET-04 : pare-feu UFW avec *confirm-or-revert* ; NET-01 relié au pare-feu.
- SEC-05, SEC-06 : configuration SSH et clés autorisées, anti-verrouillage.
- INF-09 : utilisateurs et groupes Linux, sudo.
- INF-05 : services systemd complets (toutes les unités, enable/disable, journal par unité).
- INF-11 : tâches planifiées (timers générés + crontabs).
- INF-12, INF-13 : mises à jour sélectives, simulation, unattended-upgrades, redémarrage planifié.
- SEC-02 étendu : corrections réversibles SSH, sysctl, mises à jour automatiques.
- SEC-07, SEC-08 : fail2ban complet, surveillance des connexions.
- UI-09 : checklist de premier démarrage (2FA, sauvegarde externe, alertes, pare-feu, SSH par clé, mises à jour auto).

| | |
|---|---|
| **Prérequis** | Phase 2 (toutes les opérations passent par l'agent) |
| **Dépendances** | Catalogue + niveaux de risque + aperçu |
| **Difficulté** | Moyenne |
| **Risques** | Verrouillage d'un administrateur hors de son serveur → *confirm-or-revert* obligatoire, tests sur VM avec coupure simulée |
| **Critères de validation** | Scénario « VPS neuf → sécurisé » (pare-feu, SSH par clé, mises à jour auto, 2FA) faisable entièrement depuis un téléphone en < 10 min ; aucune opération réseau ne peut couper l'accès sans retour automatique (test VM) |
| **Résultat attendu** | Plus besoin de SSH pour sécuriser et maintenir un serveur |

---

## Phase 4 — Hébergement, conteneurs, bases (3.4 → 3.6)

**3.4 — Sites et PHP**
- WEB-02/03/05/06 : assistant de création (statique, PHP, reverse proxy), modèles Nginx puis Apache puis Caddy, alias/redirections, émission de certificats.
- WEB-07/08 : PHP multi-versions, pool par site, utilisateur Linux par site.
- WEB-04 : édition de vhost avec test + diff + rollback.
- FIL-02/03/05/07/08 : éditeur, upload, permissions, compression, corbeille.

**3.5 — Conteneurs et déploiements**
- CTR-01/02/03/04/06/07/08/12 : Docker, conteneurs, images, volumes, stacks Compose, journaux, métriques, exposition web.
- WEB-09/10/11 : applications Node/Python/commande en unités systemd.
- WEB-12/13/14/15/16 : déploiement Git (releases atomiques), webhooks GitHub/GitLab/Gitea, variables d'environnement chiffrées, rollback.

**3.6 — Bases et sauvegardes**
- DB-01/02/07/08/09 : bases et utilisateurs MariaDB, import/export, sauvegarde et restauration par base ; DB-03 PostgreSQL.
- BAK-02/04/05/06/07/08/10/11 : moteur restic, politiques par ressource, destinations multiples, restauration sélective et complète depuis le panel, vérification d'intégrité, rétention GFS (l'ancien format reste lisible).

| | |
|---|---|
| **Prérequis** | Phases 2–3 |
| **Dépendances** | Coffre de secrets (Phase 1), utilisateurs Linux (Phase 3), D-09 (restic) |
| **Difficulté** | Élevée (périmètre large) |
| **Risques** | Explosion du périmètre → livrer par sous-version, chaque sous-version utile seule ; matrice serveurs web × types de sites → commencer par Nginx + PHP-FPM |
| **Critères de validation** | « Déployer un site Laravel depuis GitHub avec base MariaDB et HTTPS » en < 5 min sans SSH ; « déployer une stack Compose depuis un dépôt et l'exposer sur un domaine » idem ; restauration d'un site complet sur un autre serveur de la flotte testée automatiquement |
| **Résultat attendu** | Hostkeep remplace Cockpit + Portainer + un panel d'hébergement pour la majorité des usages |

---

## Phase 5 — Supervision avancée (3.7)

**Fonctionnalités**
- MON-11 : moteur de règles d'alertes (seuils, durées, portées, silences, maintenance) ; règles actuelles par défaut.
- MON-12 : incidents (chronologie, accusé de réception, commentaires).
- MON-20 : notifications Web Push (VAPID) ; AUT-08 : ntfy, Gotify, Slack, Matrix ; routage par règle.
- MON-02/03/04/05 : disques I/O, réseau par interface, températures, historique des processus.
- MON-09 : moniteurs HTTP/TCP/DNS configurables, y compris **depuis un autre serveur de la flotte** (NET-12).
- MON-13/14/15 : graphiques avancés, comparaison entre serveurs, rapports hebdomadaires.
- AUT-05 : remédiation bornée (« redémarrer une fois puis alerter »).
- SEC-15 (règles simples) : détection d'anomalies (nouveau port en écoute, connexions inhabituelles).

| | |
|---|---|
| **Prérequis** | Événements (Phase 1), agents (Phase 2) |
| **Difficulté** | Moyenne |
| **Risques** | Bruit d'alertes → seuils par défaut prudents, durées minimales, regroupement |
| **Critères de validation** | Détection → notification < 60 s pour les alertes critiques ; 0 doublon sur 7 jours de test ; rapport hebdomadaire envoyé et lisible sur mobile |
| **Résultat attendu** | On n'a plus besoin d'un Netdata + Uptime Kuma à côté pour un usage courant |

---

## Phase 6 — Administration professionnelle (4.0)

**Fonctionnalités**
- ORG-01/02/03/04/05 : organisations, équipes, rôles personnalisés, permissions à portée (le modèle existe depuis la Phase 1, on livre l'interface).
- ORG-08 / AUT-07 : API publique **complète** (toutes les opérations, jetons de service par organisation), CLI distante (AUT-11) ; la première version publique arrive en 3.2 (D-22).
- ORG-11 : opérations groupées avancées (lots, fenêtres).
- ORG-12 : validation à quatre yeux (option).
- SEC-12 : OIDC.
- ORG-10 : extensions niveaux 1 et 2 (intégrations, paquets d'opérations) signées ; premières extensions officielles (DNS Cloudflare/OVH, catalogue d'applications, WordPress).
- Second audit de sécurité externe avant publication de la 4.0 (le premier a lieu avant la 3.2).

| | |
|---|---|
| **Prérequis** | Phases 1–5 |
| **Difficulté** | Élevée |
| **Risques** | Fuites inter-organisations → tests d'accès croisé générés obligatoires ; complexité de l'interface pour le particulier → organisation masquée tant qu'il n'y en a qu'une |
| **Critères de validation** | Suite d'accès croisé à 100 % des routes ; un utilisateur limité à un groupe de serveurs ne voit rien d'autre (UI, API, recherche, notifications) ; audit externe sans constat High ouvert |
| **Résultat attendu** | Hostkeep est utilisable par une équipe IT et par un prestataire qui gère plusieurs clients |

---

## Phase 7 — Automatisation et IA (4.x)

**Fonctionnalités**
- AUT-01/02/03/04/10 : scripts enregistrés (permission dédiée), planification de toute opération, workflows simples avec étapes de validation, déclencheurs.
- AUT-06 : webhooks sortants signés par type d'événement.
- AI-01 à AI-10 en **extension officielle**, désactivée par défaut :
  - fournisseurs interchangeables (API compatibles, Ollama local) ;
  - lecture seule par défaut (résumé d'état, diagnostic, explication d'erreurs et de journaux) ;
  - propositions **uniquement sous forme d'opérations du catalogue**, exécutées avec les permissions de l'utilisateur, après aperçu et confirmation ;
  - données envoyées au fournisseur affichées et filtrées (secrets retirés), journalisées.
- Extensions niveau 3 (panneaux d'interface sandboxés) si le besoin est confirmé.

| | |
|---|---|
| **Prérequis** | API publique, catalogue, événements |
| **Difficulté** | Moyenne (l'essentiel est déjà en place) |
| **Risques** | Fuite de données vers un fournisseur IA → consentement explicite, filtrage, option locale ; actions erronées → jamais d'exécution sans validation humaine |
| **Critères de validation** | Aucune opération lancée par l'IA sans confirmation journalisée ; fonctionne entièrement hors ligne avec un modèle local |
| **Résultat attendu** | Un assistant utile au débutant, sans compromis sur le modèle de sécurité |

---

## Alternative : « fonctionnalités d'abord »

| | Ordre recommandé (fondations d'abord) | Alternative (fonctionnalités d'abord) |
|---|---|---|
| Ordre | 0 → 1 → 2 → 3 → 4… | 0 → 3 (pare-feu, SSH) → 4 partiel (Docker) → 1 → 2… |
| Premières fonctionnalités visibles | ~3 mois | ~1 mois |
| Coût total | Plus faible | +30 à 50 % estimés (réécriture des fonctionnalités pour le catalogue et l'agent) |
| Risque sécurité | Faible (tout naît dans le modèle cible) | Moyen (nouvelles opérations root dans le helper Bash, sans tests structurés) |
| Intérêt | Projet de long terme, contributions externes | Visibilité rapide, retour utilisateurs |

**Compromis possible :** pendant la Phase 1, livrer **une** fonctionnalité visible bâtie directement sur le catalogue (ex. pare-feu UFW en mono-serveur), pour valider le format d'opération sur un cas réel tout en montrant du progrès.
