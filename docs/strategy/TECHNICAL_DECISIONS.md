# Décisions techniques

> Registre des décisions structurantes, au format ADR simplifié. Une décision a un **statut** :
> **Actée** (déjà dans le code, conservée) · **Proposée** (recommandation, à valider par le mainteneur) · **À trancher** (plusieurs options valables, l'avis du mainteneur est nécessaire).
> Toute nouvelle décision structurante s'ajoute ici avec un numéro, et le code y fait référence (`// voir D-04`).

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
| D-05 | Dépendances PHP | Composer, liste courte de bibliothèques auditées | **À trancher** |
| D-06 | Base de données du central | MariaDB | Proposée |
| D-07 | Stockage des métriques | MariaDB partitionnée + agrégats ; TSDB en extension | Proposée |
| D-08 | Front-end | Vanilla + modules ES, sans build | **À trancher** |
| D-09 | Moteur de sauvegarde | restic | Proposée |
| D-10 | Modèle d'extensions | Progressif, signé, pas de PHP tiers non signé | Proposée |
| D-11 | Distribution et mises à jour | Releases signées sur tags | Proposée |
| D-12 | Ordre de la roadmap | Fondations avant fonctionnalités | **À trancher** |
| D-13 | Internationalisation | Catalogues JSON, codes de message côté agent | Proposée |
| D-14 | IA | Extension facultative, fournisseurs interchangeables, actions = opérations | Proposée |
| D-15 | Licence | Rester MIT (cœur et extensions officielles) | **À trancher** |
| D-16 | Nom du produit | « Hostkeep » partout | Proposée |

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

### D-05 — Dépendances PHP ⚑ à trancher
- **Contexte :** « zéro dépendance » est un atout (installation, surface d'attaque) mais certaines briques sont risquées à réimplémenter : WebAuthn, validation JSON Schema, client OIDC, client HTTP/2.
- **Options :** (a) zéro dépendance, tout réimplémenter ; (b) Composer avec liste blanche courte, dépendances **embarquées dans la release** (pas de `composer install` sur le serveur), `composer audit` en CI ; (c) framework complet.
- **Recommandation :** **(b)**. Candidats à évaluer : une bibliothèque WebAuthn maintenue, un validateur JSON Schema, PHPUnit/PHPStan (dev seulement).

### D-06 — Base de données du central
- **Options :** MariaDB (actuel), PostgreSQL, SQLite.
- **Recommandation :** **MariaDB**. Déjà installée et maîtrisée, partitionnement natif, `SKIP LOCKED` pour la file de jobs (MariaDB ≥ 10.6). SQLite seulement côté agent (file hors ligne). PostgreSQL n'apporte pas assez pour justifier une migration.

### D-07 — Stockage des métriques
- **Options :** (a) MariaDB partitionnée + agrégats 1 min / 5 min / 1 h ; (b) TSDB embarquée (VictoriaMetrics, Prometheus) ; (c) déléguer à Netdata.
- **Recommandation :** **(a)** dans le cœur (suffisant jusqu'à quelques centaines de serveurs, zéro composant de plus) ; **(b)** comme extension « export / stockage externe » pour les grandes flottes.

### D-08 — Front-end ⚑ à trancher
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

### D-11 — Distribution et mises à jour
- **Options :** (a) git pull d'une branche (actuel) ; (b) archives signées sur tags ; (c) dépôt APT signé.
- **Recommandation :** **(b)** dès la Phase 0 (corrige S3), **(c)** plus tard pour l'agent (installation et mises à jour via `apt`, familier aux administrateurs).

### D-12 — Ordre de la roadmap ⚑ à trancher
- **Options :** fondations d'abord (recommandé) ou fonctionnalités d'abord. Comparaison complète dans `PRODUCT_ROADMAP.md`, section « Alternative ».
- **Recommandation :** fondations d'abord, avec **une** fonctionnalité visible (pare-feu UFW) construite sur le nouveau catalogue pendant la Phase 1.

### D-13 — Internationalisation
- **Recommandation :** catalogues JSON par langue (`locales/fr.json`, `en.json`), clés stables (`ops.service.restart.title`), fonction `t()` en PHP et JS, pluriels ICU simples. L'agent et le helper renvoient des **codes** (`E_CONFIGTEST_FAILED`) et des paramètres, jamais des phrases. Français et anglais maintenus par le projet ; autres langues par la communauté.

### D-14 — Intelligence artificielle
- **Recommandation :** extension officielle, désactivée par défaut. Interface `AiProvider` (complétion + appel d'outils) avec adaptateurs : API compatibles (Anthropic, OpenAI, Mistral…) et local (Ollama, llama.cpp). Les « outils » exposés au modèle sont **les opérations du catalogue en lecture** ; les opérations d'écriture sont seulement **proposées** et exécutées après aperçu et confirmation, avec les permissions de l'utilisateur. Secrets filtrés avant envoi ; journal des échanges.

### D-15 — Licence ⚑ à trancher
| Option | Pour | Contre |
|---|---|---|
| **MIT** (actuel) | Simplicité, adoption maximale | Un tiers peut proposer un service fermé dérivé |
| AGPL-3.0 | Les services dérivés doivent publier leurs modifications | Freine certaines adoptions en entreprise ; changement de licence nécessite l'accord des contributeurs (facile aujourd'hui, mainteneur unique) |
| MIT + extensions commerciales | Financement possible | Contraire au positionnement « tout le cœur libre » |
- **Recommandation :** rester **MIT** sauf projet de financement ou crainte de reprise commerciale ; si changement il doit y avoir, le faire **avant** les premières contributions externes.

### D-16 — Nom du produit
- **Constat :** « Hostkeep » dans le code et la documentation, « HostKeep » dans le nom du dépôt.
- **Recommandation :** choisir une graphie et l'appliquer partout (dépôt, README, interface, paquets). Proposition : « Hostkeep » (déjà majoritaire).
