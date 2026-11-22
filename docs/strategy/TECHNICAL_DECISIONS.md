# Décisions techniques

> Registre des décisions structurantes, au format ADR simplifié. Une décision a un **statut** :
> **Actée** (déjà dans le code, conservée) · **Proposée** (recommandation, à valider par le mainteneur) · **À trancher** (plusieurs options valables, l'avis du mainteneur est nécessaire).
> Toute nouvelle décision structurante s'ajoute ici avec un numéro, et le code y fait référence (`// voir D-04`).
>
> **Mis à jour pour la 2.10.1 et la vision « produit public »** : Hostkeep doit être distribué à grande échelle, installé par n'importe qui, utilisé par plusieurs personnes et organisations, maintenu sur des années. Chaque décision ci-dessous est relue à cette aune ; D-17 à D-22 en découlent directement.

## Récapitulatif

| ID | Sujet | Recommandation | Statut |
|---|---|---|---|
| A-01 | Web non privilégié + exécuteur root en liste blanche | Conserver et généraliser | Actée |
| A-02 | Installation native (pas Hostkeep dans Docker) pour piloter l'hôte | Conserver | Actée |
| A-03 | MariaDB par socket unix, sans mot de passe | Conserver | Actée |
| A-04 | Pas de terminal web dans le cœur | Conserver (extension désactivée par défaut au mieux) | Actée |
| A-05 | Cohabiter avec la configuration existante, jamais d'écrasement | Conserver | Actée |
| D-01 | Évolution ou refonte | Évolution incrémentale | Proposée |
| D-02 | Langage du serveur central | Rester en PHP, structuré | Proposée |
| D-03 | Langage de l'agent | Go | **À trancher** |
| D-04 | Transport agent ↔ central | Sondage HTTPS sortant, puis WebSocket | **À trancher** |
| D-05 | Dépendances PHP | Composer, dépendances limitées | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-06 | Base de données du central | MariaDB | Proposée |
| D-07 | Stockage des métriques | MariaDB partitionnée + agrégats ; TSDB en extension | Proposée |
| D-08 | Front-end | Vanilla JS modulaire | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-09 | Moteur de sauvegarde | restic | Proposée |
| D-10 | Modèle d'extensions | Progressif, signé, pas de PHP tiers non signé | Proposée |
| D-11 | Distribution et mises à jour | Tags signés (fait) → archives signées + dépôt APT | Partiellement actée |
| D-12 | Ordre de la roadmap | Fondations d'abord | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-13 | Internationalisation | Catalogues JSON, codes de message côté agent | Proposée |
| D-14 | IA | Extension facultative, fournisseurs interchangeables, actions = opérations | Proposée |
| D-15 | Licence | Apache-2.0 | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-16 | Nom du produit | « HostKeep » (marque) ; identifiants techniques en minuscules | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-17 | Façons d'installer le serveur central | Natif (actuel) + image Docker du central pour la gestion à distance | **À trancher** |
| D-18 | Langue de référence du projet | Anglais de référence, français disponible | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-19 | Versions et support | Version actuelle + précédente | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-20 | Contributions et gouvernance | DCO | **Validée** le 22/11/2026 (conséquences : `PHASE_1_PLAN.md` §1) |
| D-21 | Télémétrie | Aucune par défaut ; compteur anonyme d'installations seulement en option explicite | **À trancher** |
| D-23 | Outil du site de documentation | MkDocs Material | **Validée** le 22/11/2026 |
| D-24 | Version minimale de PHP en 3.x | PHP 8.1 minimum en 3.x (Ubuntu 22.04 conservé), 8.2 en 4.0 | **Validée** le 22/11/2026 |
| D-25 | Signature des archives de version | Signatures SSH (même clé que les tags) | **Validée** le 22/11/2026 |
| D-22 | Calendrier de l'API publique | Jetons à portée + OpenAPI publics dès la 3.2 (lecture et opérations courantes), complète en 4.0 | **À trancher** |

---

## Décisions actées (existant)

### A-01 — Web non privilégié, exécuteur root en liste blanche
- **Contexte :** un panel d'administration doit agir en root sans exposer root au web.
- **Décision :** PHP-FPM sous un utilisateur dédié ; un seul binaire autorisé par sudo (`hostkeep-action`), liste blanche de verbes, revalidation des arguments.
- **Conséquence pour la cible :** le principe devient « central non privilégié → agent non privilégié → exécuteur root local ». Voir `TARGET_ARCHITECTURE.md`.

### A-02 — Pas de Docker pour Hostkeep lui-même (en mode hôte)
- **Raison :** piloter l'hôte depuis un conteneur exige `--privileged` et le montage de `/`, ce qui annule le modèle de sécurité.
- **Nuance pour la cible :** le **central** pourra être proposé en image Docker pour qui ne veut que gérer des serveurs distants (il ne pilote alors rien localement). Les **agents** restent natifs.

### A-03 — MariaDB par socket unix
- **Raison :** aucun mot de passe de base stocké, privilèges SQL par utilisateur système.

### A-04 — Pas de terminal web
- **Raison :** le terminal root dans le navigateur contourne toutes les protections (liste blanche, audit structuré, aperçu).
- **Cible :** à envisager seulement en extension désactivée par défaut, permission dédiée, sessions enregistrées.

### A-05 — Cohabitation
- **Raison :** différenciateur majeur face à HestiaCP, CloudPanel, Plesk, 1Panel (voir `COMPETITIVE_ANALYSIS.md`).
- **Conséquence :** les fichiers générés sont marqués « géré par Hostkeep » ; les ressources existantes sont lues, et ne deviennent gérées que par une action explicite (« adopter »).

---

## Décisions proposées ou à trancher

### D-01 — Évolution ou refonte
- **Options :** (a) refonte (nouveau projet, nouvelle pile) ; (b) évolution incrémentale.
- **Analyse :** le code est propre, sans dépendance, avec un modèle de sécurité correct ; ses limites (mono-serveur, monolithe, pas de tests) se corrigent par extraction et structuration. Une refonte ferait perdre la stabilité, l'installateur éprouvé et 10 versions de corrections.
- **Recommandation :** **(b)**, selon le chemin de migration M0 → M5 (`TARGET_ARCHITECTURE.md` §13).

### D-02 — Langage du serveur central
| Option | Pour | Contre |
|---|---|---|
| **PHP 8.2+ structuré** (recommandé) | Continuité, mainteneur à l'aise, déploiement trivial, PHP-FPM `ondemand` très économe au repos | Processus longs (worker, WebSocket) moins naturels ; typage moins strict que Go |
| Go (tout réécrire) | Un seul langage avec l'agent ; binaire unique | Réécriture complète (contre D-01) |
| Laravel / Symfony | Écosystème riche (files, ORM, auth) | Dépendances lourdes, mises à jour fréquentes, surface d'attaque, perte de la légèreté |
- **Recommandation :** PHP structuré sans framework complet, avec quelques composants (voir D-05). Le worker en PHP CLI suffit ; si un jour la passerelle WebSocket est nécessaire, elle sera en Go (D-04).

### D-03 — Langage de l'agent ⚑ à trancher
| Option | Pour | Contre |
|---|---|---|
| **Go** (recommandé) | Binaire statique multi-architecture, ~10–20 Mo de RAM, bibliothèques solides (TLS, Docker, D-Bus, systemd, SQLite pur Go), modèle éprouvé par des agents comparables (Portainer, Beszel, collecteurs Go de Netdata) | Nouveau langage à apprendre et maintenir ; deux piles dans le projet |
| Rust | Sécurité mémoire, très faible empreinte | Courbe d'apprentissage plus raide, temps de compilation, moins de bibliothèques système mûres |
| PHP CLI | Une seule pile | Dépendance à PHP sur chaque serveur géré (non souhaitable), mémoire, processus long |
| Bash + PHP actuels étendus | Zéro nouveau langage | Non testable à l'échelle, pas de mTLS/WebSocket propre, maintenance difficile |
- **Recommandation :** Go. **Ce que ça implique pour le mainteneur :** apprendre Go (langage volontairement simple), CI multi-architecture, signature des binaires.

### D-04 — Transport agent ↔ central ⚑ à trancher
| Option | Pour | Contre |
|---|---|---|
| **A. Agent → central, sondage HTTPS** (recommandé pour démarrer) | Aucun port entrant sur les serveurs ; traverse NAT et proxies ; servi par l'application PHP existante ; simple à déboguer | Latence de 2 à 30 s ; pas de flux continu (journaux en direct) ; coût linéaire en requêtes |
| B. Agent → central, WebSocket (passerelle Go) | Temps réel, flux, une connexion par agent | Composant supplémentaire côté central |
| C. Central → agent (agent qui écoute un port, comme Portainer Agent) | Simple côté central | Port ouvert sur chaque serveur, exposition, NAT |
| D. Central → serveur par SSH (comme Coolify) | Rien à installer | Clé SSH puissante sur le central = flotte entière ; parsing de sortie de commandes ; pas de politique locale |
- **Recommandation :** **A puis B**, avec un protocole de messages indépendant du transport. Rejeter C et D pour des raisons de sécurité.

### D-05 — Dépendances PHP ✅ validée le 22/11/2026
- **Contexte :** « zéro dépendance » est un atout (installation, surface d'attaque) mais certaines briques sont risquées à réimplémenter : WebAuthn, validation JSON Schema, client OIDC, client HTTP/2.
- **Options :** (a) zéro dépendance, tout réimplémenter ; (b) Composer avec liste blanche courte, dépendances **embarquées dans la release** (pas de `composer install` sur le serveur), `composer audit` en CI ; (c) framework complet.
- **Recommandation :** **(b)**. Candidats à évaluer : une bibliothèque WebAuthn maintenue, un validateur JSON Schema, PHPUnit/PHPStan (dev seulement).

### D-06 — Base de données du central
- **Options :** MariaDB (actuel), PostgreSQL, SQLite.
- **Recommandation :** **MariaDB**. Déjà installée et maîtrisée, partitionnement natif, `SKIP LOCKED` pour la file de jobs (MariaDB ≥ 10.6). SQLite seulement côté agent (file hors ligne). PostgreSQL n'apporte pas assez pour justifier une migration.

### D-07 — Stockage des métriques
- **Options :** (a) MariaDB partitionnée + agrégats 1 min / 5 min / 1 h ; (b) TSDB embarquée (VictoriaMetrics, Prometheus) ; (c) déléguer à Netdata.
- **Recommandation :** **(a)** dans le cœur (suffisant jusqu'à quelques centaines de serveurs, zéro composant de plus) ; **(b)** comme extension « export / stockage externe » pour les grandes flottes.

### D-08 — Front-end ✅ validée le 22/11/2026
| Option | Pour | Contre |
|---|---|---|
| **Vanilla + modules ES natifs, sans build** (recommandé) | Continuité, 0 dépendance, rapide, CSP stricte facile | Plus de code « à la main » pour les composants complexes (éditeur, graphiques) |
| Preact / Lit sans build (import d'un fichier ES local) | Composants réactifs, ~10 Ko | Nouvelle abstraction, dépendance à suivre |
| Vue / Svelte / React avec build (Vite) | Écosystème, productivité sur une grosse interface | Chaîne de build, `node_modules`, poids, rupture avec l'existant |
- **Recommandation :** vanilla + modules ES ; réévaluer à l'entrée de la Phase 4 (éditeur de fichiers, formulaires complexes). Pour l'éditeur de code, une version auto-hébergée de CodeMirror 6 empaquetée en un seul fichier est acceptable.

### D-09 — Moteur de sauvegarde
- **Options :** restic, BorgBackup, Kopia, tar actuel.
- **Analyse :** restic = binaire unique, chiffrement et déduplication natifs, nombreux backends (S3, SFTP, B2, REST, rclone), `check` et restauration sélective ; Borg exige Borg côté distant (ou un service spécialisé) ; Kopia est comparable à restic mais moins répandu dans les paquets des distributions.
- **Recommandation :** **restic**, en conservant le format tar actuel en option et la lecture des anciennes sauvegardes.

### D-10 — Modèle d'extensions
- **Recommandation :** trois niveaux progressifs (intégrations → paquets d'opérations exécutés par l'agent → panneaux d'interface sandboxés), signature obligatoire, permissions déclarées et acceptées. **Pas de code PHP tiers non signé chargé dans le central.** Détails : `TARGET_ARCHITECTURE.md` §9.

### D-11 — Distribution et mises à jour (partiellement actée)
- **Fait en 2.10.0 :** chaque serveur garde un clone git ; « Mettre à jour » n'installe qu'un **tag signé en SSH** dès qu'une clé est déclarée (`docs/RELEASING.md`). Choisi à la place de minisign : aucun outil de plus.
- **Limite pour un produit public :** git sur chaque serveur (dépôt « sale » = mise à jour refusée, dépendance à GitHub, téléchargement de tout l'historique) ; l'installation en une ligne (`curl … | sudo bash`) clone `main` **sans vérifier de signature**.
- **Options pour la suite :** (a) garder git + tags signés ; (b) **archives de version signées** (`.tar.gz` + signature) téléchargées et vérifiées par l'installateur et la mise à jour, sans git ; (c) **dépôt APT signé** (`apt install hostkeep`, mises à jour par `apt upgrade`).
- **Recommandation :** **(b)** en Phase 1 (l'installateur en une ligne vérifie l'archive avant d'exécuter quoi que ce soit), **(c)** en Phase 2 pour l'agent puis le central. Garder (a) comme mode « développeur ».

### D-12 — Ordre de la roadmap ✅ validée le 22/11/2026
- **Options :** fondations d'abord (recommandé) ou fonctionnalités d'abord. Comparaison complète dans `PRODUCT_ROADMAP.md`, section « Alternative ».
- **Recommandation :** fondations d'abord, avec **une** fonctionnalité visible (pare-feu UFW) construite sur le nouveau catalogue pendant la Phase 1.

### D-13 — Internationalisation
- **Recommandation :** catalogues JSON par langue (`locales/fr.json`, `en.json`), clés stables (`ops.service.restart.title`), fonction `t()` en PHP et JS, pluriels ICU simples. L'agent et le helper renvoient des **codes** (`E_CONFIGTEST_FAILED`) et des paramètres, jamais des phrases. Français et anglais maintenus par le projet ; autres langues par la communauté.

### D-14 — Intelligence artificielle
- **Recommandation :** extension officielle, désactivée par défaut. Interface `AiProvider` (complétion + appel d'outils) avec adaptateurs : API compatibles (Anthropic, OpenAI, Mistral…) et local (Ollama, llama.cpp). Les « outils » exposés au modèle sont **les opérations du catalogue en lecture** ; les opérations d'écriture sont seulement **proposées** et exécutées après aperçu et confirmation, avec les permissions de l'utilisateur. Secrets filtrés avant envoi ; journal des échanges.

### D-15 — Licence ✅ validée le 22/11/2026
| Option | Pour | Contre |
|---|---|---|
| MIT (actuel) | Simplicité, adoption maximale | Pas de clause de brevets ; un hébergeur peut vendre un « Hostkeep géré » fermé |
| **Apache-2.0** | Permissive comme MIT, **avec licence de brevets** et cadre clair des contributions : la référence des entreprises (Coolify, Kubernetes) | Même risque de reprise fermée que MIT |
| **AGPL-3.0** | Quiconque modifie Hostkeep et l'offre comme service doit publier ses modifications (protège le projet d'un SaaS fermé) ; utilisé par Komodo, Grafana | Certaines entreprises l'interdisent en interne ; extensions tierces plus délicates |
| Cœur libre + édition commerciale | Financement | Contraire au positionnement « aucune fonction essentielle payante » |
- **Pourquoi c'est urgent :** changer de licence demande l'accord de **tous** les contributeurs. Aujourd'hui tu es seul : c'est le dernier moment où c'est simple.
- **Recommandation :** **Apache-2.0** si l'objectif prioritaire est l'adoption la plus large (particuliers, entreprises, intégrateurs) ; **AGPL-3.0** si tu veux empêcher qu'un tiers vende une version fermée de ton travail. Dans les deux cas, éviter de rester en MIT (pas de clause de brevets).

### D-16 — Nom du produit ✅ validée le 22/11/2026
- **Constat :** « Hostkeep » dans le code et la documentation, « HostKeep » dans le nom du dépôt.
- **Pour un produit public**, le nom devient une marque : vérifier sa disponibilité (marques déposées, nom de domaine, paquets `apt`/Docker Hub/GitHub), puis l'écrire d'une seule façon partout. Changer de nom après une adoption large coûte très cher.
- **Recommandation :** vérifier la disponibilité, puis « Hostkeep » (déjà majoritaire).

---

## Décisions issues de la vision « produit public »

### D-17 — Façons d'installer le serveur central ⚑ à trancher
| Option | Pour | Contre |
|---|---|---|
| Natif seulement (actuel) | Un seul mode à maintenir ; nécessaire pour gérer la machine elle-même | Les équipes qui veulent un central séparé, sur Kubernetes ou en conteneur, ne peuvent pas |
| **Natif + image Docker du central** | Le central (qui ne pilote rien localement, voir A-02) se déploie partout ; les serveurs gérés gardent l'agent natif | Une image à publier, signer et maintenir |
| Docker seulement | Simple à distribuer | Impossible de gérer l'hôte proprement (A-02) |
- **Recommandation :** natif + image Docker du central, à partir de la Phase 2 (quand l'agent existe).

### D-18 — Langue de référence du projet ✅ validée le 22/11/2026
| Option | Pour | Contre |
|---|---|---|
| Tout en français (actuel) | Ta langue, cohérent aujourd'hui | Limite fortement les contributeurs et utilisateurs hors francophonie |
| **Anglais pour le code, l'API, la doc de référence, les tickets et commits ; interface et doc utilisateur bilingues** | Standard des projets open source internationaux ; le français reste une langue de premier rang | Effort de traduction continu ; changement d'habitude pour les commits |
| Bilingue partout | Accessible à tous | Double travail sur chaque texte, risque de divergence |
- **Recommandation :** l'option du milieu, appliquée à partir de la Phase 1 (en même temps que l'i18n). Le CHANGELOG devient bilingue ou anglais.

### D-19 — Versions et support ✅ validée le 22/11/2026
- **Constat :** 11 versions en 11 jours, aucune politique de support. Les administrateurs d'un produit public ont besoin de savoir combien de temps une version reçoit des correctifs.
- **Options :** (a) seulement la dernière version (actuel, écrit dans `SECURITY.md`) ; (b) **la dernière mineure + la précédente pour les correctifs de sécurité**, dates de fin de support publiées ; (c) versions LTS annuelles maintenues 2 ans.
- **Recommandation :** (b) dès la 3.0, (c) à envisager après la 4.0 quand des entreprises en dépendront. Rythme conseillé : une mineure par mois au plus, des correctifs à la demande.

### D-20 — Contributions et gouvernance ✅ validée le 22/11/2026
| Option | Pour | Contre |
|---|---|---|
| **DCO** (« Signed-off-by » sur chaque commit) | Léger, utilisé par le noyau Linux ; garantit l'origine du code | Ne permet pas de changer de licence plus tard sans accord |
| CLA (accord de contribution) | Permet de changer de licence ou de double licence | Rebute de nombreux contributeurs ; demande un cadre juridique |
| Rien (actuel) | Aucun effort | Origine du code non tracée |
- Avec, dans tous les cas : code de conduite, modèles de tickets et de PR, revue obligatoire, protection de `main`.
- **Recommandation :** DCO, sauf si tu envisages une édition commerciale (alors CLA).

### D-21 — Télémétrie ⚑ à trancher
- **Options :** (a) aucune (actuel) ; (b) **compteur anonyme d'installations en option explicite** (version, distribution, nombre de serveurs, rien d'autre) ; (c) télémétrie activée par défaut.
- **Recommandation :** (b), désactivée par défaut, documentée, avec la liste exacte des données envoyées. (c) est contraire à l'image d'outil de sécurité.

### D-22 — Calendrier de l'API publique ⚑ à trancher
- **Constat :** la roadmap prévoyait les jetons d'API publics en Phase 6 (4.0). Pour un produit professionnel, l'automatisation (scripts, CI, Terraform, supervision externe) est attendue bien plus tôt.
- **Options :** (a) en 4.0 (roadmap d'origine) ; (b) **dès la 3.2** : OpenAPI publiée et jetons à portée pour la lecture et les opérations de niveau `routine`/`change`, puis complète en 4.0 ; (c) dès la 3.0.
- **Recommandation :** (b). (c) figerait trop tôt un contrat que le multi-serveurs va changer.
