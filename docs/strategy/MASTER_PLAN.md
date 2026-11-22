# Plan maître

> Document d'entrée pour toute session de développement. Il résume les neuf autres et dit **quoi faire ensuite**.
> Rédigé le 22/11/2026 sur la base de la 2.9.0 ; **revu le 22/11/2026 pour la 2.10.1** (tag `v2.10.1`) et pour la vision d'un **produit public distribué à grande échelle**.

## Les documents

| Document | Répond à |
|---|---|
| [`PRODUCT_VISION.md`](PRODUCT_VISION.md) | Pourquoi, pour qui, exigences d'un produit public, principes, **standards de qualité mesurables** |
| [`CURRENT_STATE_AUDIT.md`](CURRENT_STATE_AUDIT.md) | Que vaut le code ? **§0 : statut de chaque constat à la 2.10.1** |
| [`COMPETITIVE_ANALYSIS.md`](COMPETITIVE_ANALYSIS.md) | Que font 1Panel, Coolify, Portainer, Cockpit, Webmin, Netdata, HestiaCP, Plesk, CloudPanel et d'autres ? |
| [`FEATURE_CATALOG.md`](FEATURE_CATALOG.md) | Toutes les fonctionnalités envisageables, avec un identifiant stable (`NET-02`…) |
| [`FEATURE_GAP_ANALYSIS.md`](FEATURE_GAP_ANALYSIS.md) | Ce qui manque, priorités, extensions ; **§1 bis : écarts liés au produit public** |
| [`TARGET_ARCHITECTURE.md`](TARGET_ARCHITECTURE.md) | Central + agent, catalogue d'opérations, modèle de données, API, extensions ; **§15 : limites pour un produit public et correctifs** |
| [`SECURITY_ROADMAP.md`](SECURITY_ROADMAP.md) | Modèle de menace, exigences, niveaux de risque, tests ; ce qui est livré et ce qui reste |
| [`PRODUCT_ROADMAP.md`](PRODUCT_ROADMAP.md) | Les phases, dans quel ordre et pourquoi ; ajouts 🌍 du produit public |
| [`TECHNICAL_DECISIONS.md`](TECHNICAL_DECISIONS.md) | Les choix structurants, leurs alternatives, et les **13 qui attendent ta décision** |

---

## 1. Hostkeep à la 2.10.1, en une minute

- **Ce qu'il est :** un panel **mono-serveur** natif (PHP, MariaDB, Bash, systemd), mobile-first, multi-utilisateur (4 rôles), avec un vrai modèle de sécurité : web sans root, actions en liste blanche revalidées en root, base sans mot de passe, CSP stricte, TOTP.
- **Ce que la 2.10 a apporté :** les failles de l'audit corrigées (S1, S2, S4, S5), deux bugs de notifications et de journal (B1, B2), deux bugs d'installation trouvés en installant pour de vrai (B9, B10), le mécanisme de mises à jour signées (S3, **pas encore actif**), un socle de tests (PHP 8.1–8.4, helper, intégration, 45 attaques), `SECURITY.md`, `CONTRIBUTING.md`.
- **Vérifié en conditions réelles (2.10.1) :** installation sur Debian 12 (Nginx, Apache, proxy), Debian 13 (Caddy), Ubuntu 22.04 (Apache, PHP 8.1), Ubuntu 24.04 (Nginx) ; 45 attaques bloquées sur trois de ces installations ; Semgrep sans vrai constat.
- **Ses faiblesses :** il **observe** beaucoup mais **gère** peu (pas de pare-feu, d'utilisateurs, de Docker, de création de site ou de base, de fichiers en écriture) ; **mono-serveur** par construction ; rôles globaux ; tout en français ; distribution par `git` sans signature vérifiée à l'installation.

## 2. Les constats qui comptent, et où ils en sont

| # | Constat | Statut 2.10.1 |
|---|---|---|
| 1 | rclone configurable exécuté en root (S1) | ✅ corrigé ; rclone encore root → Phase 1 |
| 2 | Administrateur → secrets du Super Admin (S2) | ✅ corrigé |
| 3 | Mises à jour non signées (S3) | 🟡 mécanisme livré, **actif seulement quand tu déclares ta clé** |
| 4 | Sorties de tâches (S4), proxy de confiance (S5) | ✅ corrigés |
| 5 | Notifications répétées (B2), résultat absent du journal (B1) | ✅ corrigés |
| 6 | Installateur muet sans DNS, Caddy jamais installé (B9, B10) | ✅ corrigés en 2.10.1 |
| 7 | Journal d'audit modifiable (S6), dumps lisibles par le web (S7), points mineurs (S8) | ⚪ ouverts → Phase 1 (S8 partiellement en Phase 2) |
| 8 | Liste blanche définie 4 fois (B7) | ⚪ → catalogue d'opérations, Phase 1 |
| 9 | Mono-serveur codé en dur | ⚪ → agent, Phase 2 |
| 10 | Choix qui limitent un produit public (installation non vérifiée, réglages globaux, API tardive, français seul, support non défini…) | ⚪ → correctifs planifiés, `TARGET_ARCHITECTURE.md` §15 |

**Bilan sécurité :** dans le code de la 2.10.1, aucune faille de gravité moyenne ou élevée n'est ouverte, **à une réserve près** : tant que la clé de signature n'est pas déclarée, une compromission du dépôt GitHub permettrait encore de pousser du code exécuté en root par « Mettre à jour ».

## 3. La cible, en une minute

- **Un serveur central** (l'application PHP actuelle, structurée) : interface, API `/api/v1`, comptes, organisations, rôles à portée, catalogue d'opérations, file de jobs, événements, alertes, incidents. **Sans aucun privilège.** Installé nativement, ou en conteneur pour la gestion à distance (D-17).
- **Un agent par serveur** (Go, D-03) : collecte et dialogue **en sortie** avec le central (mTLS, requêtes signées) ; un exécuteur root local qui n'exécute que les opérations qu'il connaît et que sa **politique locale** autorise.
- **Un catalogue d'opérations** : chaque action déclarée une fois (paramètres, permission, risque, confirmation, aperçu, réversibilité) ; il alimente l'interface, l'API, l'audit, les automatisations et l'IA.
- **Le mono-serveur reste** le cas par défaut : central + agent local, installés par la même commande.

## 4. La vision « produit public » est-elle respectée ?

Oui dans les grandes lignes : le modèle cible (central + agents, organisations et rôles à portée, API, extensions signées, mises à jour signées) est celui d'un produit distribué. **Onze choix actuels limitaient l'ambition** ; chacun a maintenant un correctif planifié (`TARGET_ARCHITECTURE.md` §15) :

| Limite | Correctif | Phase |
|---|---|---|
| L1 Installation en une ligne non vérifiée, clone git sur chaque serveur | Archives signées vérifiées avant exécution, puis dépôt APT | 1, 2 |
| L2 Réglages globaux | Réglages instance / organisation / serveur | 1 (modèle) |
| L3 Rôles globaux | Rôles à portée + tests d'accès croisé dès la Phase 1 | 1 |
| L4 API publique seulement en 4.0 | Jetons et OpenAPI dès la 3.2 (D-22) | 2 |
| L5 Tout en français | Anglais de référence, interface et doc bilingues (D-18) | 1 |
| L6 Debian/Ubuntu, MariaDB, systemd | Abstraction dans l'agent ; central en conteneur | 2, 4 |
| L7 Mainteneur unique, pas de politique de support | D-19, D-20, tests dans le CI | 1 |
| L8 Sondage HTTPS limité à ~150 serveurs | Passerelle WebSocket, TSDB en extension | 2 → 5 |
| L9 Audit externe seulement avant 4.0 | Premier audit avant la 3.2 | fin de 2 |
| L10 Pas de documentation hors README | Site de documentation versionné, débutants et professionnels | 1 |
| L11 Pas de sauvegarde du central | Sauvegarde chiffrée + reprise testée | 2 |

## 5. Feuille de route

| Phase | Version | Contenu | Durée indicative |
|---|---|---|---|
| 0 ✅ | 2.10.0 / 2.10.1 | Correctifs sécurité, mécanisme de signature, socle de tests | livrée |
| 1 | 3.0 | Fondations : migrations, modules, catalogue, jobs, événements, API v1 interne, i18n FR/EN, passkeys, modèle multi-organisations, archives signées, documentation publique | 8–10 sem. |
| 2 | 3.1–3.2 | Agent local (PHP sans sudo), agents distants, vue flotte, API publique, APT, image Docker du central, audit externe | 10–12 sem. |
| 3 | 3.3 | Hôte : pare-feu, SSH, utilisateurs, services, tâches, mises à jour | 6–8 sem. |
| 4 | 3.4–3.6 | Sites et PHP par site, fichiers, Docker/Compose, déploiement Git, bases, sauvegardes restic | 12–16 sem. |
| 5 | 3.7 | Alertes configurables, incidents, Web Push, rapports | 6–8 sem. |
| 6 | 4.0 | Interface des organisations et équipes, rôles personnalisés, API complète, extensions, second audit | 8–10 sem. |
| 7 | 4.x | Workflows, remédiation, IA (extension facultative) | 8+ sem. |

Total indicatif jusqu'à la 4.0 : **14 à 18 mois** à temps plein pour une personne (2 mois de plus qu'avant, pour la distribution, la documentation bilingue et l'audit externe qu'impose un produit public). Des contributeurs réguliers, rendus possibles par la Phase 1, peuvent réduire ce délai.

---

## 6. Décisions qui attendent ton avis

Détails, avantages et inconvénients dans [`TECHNICAL_DECISIONS.md`](TECHNICAL_DECISIONS.md).

### 6.1 À trancher avant de lancer la Phase 1

| ID | Question | Recommandation |
|---|---|---|
| **D-15** | Licence : MIT (actuel), Apache-2.0 ou AGPL-3.0 ? **Urgent** : facile tant que tu es le seul contributeur | Apache-2.0 pour l'adoption maximale ; AGPL-3.0 pour empêcher un SaaS fermé |
| **D-12** | Fondations d'abord, ou fonctionnalités visibles d'abord ? | Fondations d'abord, avec le pare-feu UFW comme premier cas réel du catalogue |
| **D-18** | Langue de référence du projet | Anglais pour le code, l'API, la doc de référence et les contributions ; interface et doc utilisateur FR + EN |
| **D-05** | Composer et quelques bibliothèques auditées (WebAuthn, JSON Schema, PHPUnit), embarquées dans la release | Oui, liste blanche courte |
| **D-08** | Front : rester vanilla (modules ES) ou adopter un framework | Vanilla + modules ES, réévalué en Phase 4 |
| **D-16** | Nom définitif (après vérification de disponibilité) | « Hostkeep » |
| **D-19** | Politique de versions et de support | Dernière mineure + la précédente pour la sécurité, dates de fin publiées |
| **D-20** | Cadre de contribution | DCO + code de conduite + modèles de tickets |

### 6.2 À trancher avant la Phase 2 (pas bloquantes pour démarrer)

| ID | Question | Recommandation |
|---|---|---|
| **D-03** | Langage de l'agent | Go |
| **D-04** | Transport agent ↔ central | Sondage HTTPS sortant, WebSocket ensuite ; jamais de port ouvert sur les serveurs, jamais de SSH root depuis le central |
| **D-17** | Image Docker du central en plus de l'installation native | Oui, à partir de la Phase 2 |
| **D-22** | Calendrier de l'API publique | Jetons à portée + OpenAPI dès la 3.2 |
| **D-21** | Télémétrie | Aucune par défaut ; compteur anonyme seulement en option explicite |

### 6.3 Recommandations à valider en bloc
D-01 (évolution, pas de refonte), D-02 (central en PHP), D-06 (MariaDB), D-07 (métriques dans MariaDB, TSDB en extension), D-09 (restic), D-10 (extensions signées en trois niveaux), D-11 (archives signées puis APT), D-13 (i18n par catalogues JSON), D-14 (IA en extension facultative).

---

## 7. Actions à faire de ton côté

### Sur GitHub
1. **Choisir la licence (D-15)** et me dire laquelle : je ferai le changement (fichier `LICENSE`, README, en-têtes) dans une PR.
2. **Créer ta clé de signature** et la déclarer (procédure complète : [`docs/RELEASING.md`](../RELEASING.md)). Tant que ce n'est pas fait, S3 reste ouvert. La première version qui contient ta clé publique doit elle-même être signée.
3. **Protéger `main` et `develop`** (*Settings › Rules › Rulesets*) : PR obligatoire, CI verte exigée (vérifications, PHP 8.1 à 8.4, gitleaks, schéma), force-push et suppression interdits ; protéger aussi les tags `v*`.
4. **Activer** (*Settings › Code security*) : *Private vulnerability reporting* (c'est le canal annoncé dans `SECURITY.md`), *Dependabot alerts*, *Secret scanning* et *Push protection*.
5. **Publier les notes de version** des tags `v2.10.0` et `v2.10.1` dans *Releases* (le texte est dans `CHANGELOG.md`) : c'est là que les utilisateurs les cherchent.
6. Facultatif : supprimer les branches déjà fusionnées (`fix/s1-rclone-allowlist`, `fix/s2-admin-boundary`, `fix/phase0-hardening`, `fix/doc-et-verifications`) ; renseigner la description et les sujets (topics) du dépôt ; décider de la graphie du nom (D-16) avant d'éventuellement renommer le dépôt.
7. Facultatif : installer `gh` sur ton poste (`sudo apt install gh`, puis `gh auth login`) pour que j'ouvre et suive les PR sans passer par le navigateur.

### Sur ton VPS
1. **Passer en 2.10.1** : *Administration › Version › Mettre à jour*, ou `sudo hostkeep update`. La page affichera « non vérifiées » jusqu'à ta première version signée.
2. **Si tu utilises la sauvegarde externe « Autre »** : vérifie qu'aucune alerte « Configuration rclone refusée » n'apparaît ; sinon retire l'option nommée.
3. **Si le panel est derrière un reverse proxy** (Traefik, Nginx Proxy Manager…) : vérifie que `/var/log/hostkeep/http-access.log` montre les vraies IP des visiteurs ; sinon `sudo hostkeep web proxy --trusted-proxy ADRESSE_DU_PROXY`.
4. **Relever les mesures de référence** (le seul point de la Phase 0 non fait) et me les transmettre :
   ```bash
   systemctl status hostkeep --no-pager | grep -E 'Memory|CPU'
   ```
   ```bash
   time sudo hostkeep collect minute
   ```
   ```bash
   free -m; nproc; hostkeep version
   ```
5. Les points « À faire de ton côté » du README restent valables : un compte par personne, double authentification, pare-feu (`ufw`), liste d'IP autorisées si possible.

---

## 8. Lancer la Phase 1

Dès que les décisions du §6.1 sont prises :
1. **ADR du catalogue d'opérations** : format exact, conversion des 21 verbes actuels, génération des validations PHP, du front et des tests.
2. **Modèle de données cible** : migrations versionnées, organisation « Default », `server_id`, rôles à portée, réglages à trois niveaux, tests d'accès croisé.
3. **Distribution** : archives signées, installateur qui vérifie avant d'exécuter.
4. **i18n et documentation** selon D-18 ; politique de support (D-19) et cadre de contribution (D-20) publiés.
5. **Reports de la Phase 0** : rclone non root, S7, S8, B11, tests d'intégration dans le CI.
6. Le pare-feu UFW comme premier cas réel du catalogue (si D-12 est validée).

---

## 9. Comment utiliser ces documents dans les prochaines sessions

- **Commencer chaque session** par ce fichier, puis le document de la phase en cours.
- **Référencer les identifiants** : fonctionnalités (`WEB-02`), constats (`S1`, `B2`), limites (`L4`), décisions (`D-04`) dans les branches, commits, PR et commentaires.
- **Mettre à jour** à chaque livraison : `CURRENT_STATE_AUDIT.md` §0, `FEATURE_GAP_ANALYSIS.md`, `TECHNICAL_DECISIONS.md`, et ce plan à la fin de chaque phase.
- **Penser produit public** : aucune fonctionnalité n'est terminée sans permissions à portée, API, traduction, documentation et tests (`PRODUCT_VISION.md` §1 bis).
- **Distinguer** toujours fait vérifié, hypothèse et recommandation.

## 10. Ce qui n'a pas pu être vérifié

- Performances et consommation sur un vrai VPS (estimées seulement).
- ARM64.
- L'analyse concurrentielle repose sur la documentation publique connue à mi-2026, sans installation des produits ; les points « à revérifier » doivent l'être avant toute communication publique.
- Le dossier non suivi `admin-dashboard/` n'a été ni modifié ni supprimé (il est ignoré par Git).
