# Plan maître

> Document d'entrée pour toute session de développement. Il résume les neuf autres et dit **quoi faire ensuite**.
> Rédigé le 22/11/2026 sur la base de Hostkeep 2.9.0 (commit `1b34003`).

## Les documents

| Document | Répond à |
|---|---|
| [`PRODUCT_VISION.md`](PRODUCT_VISION.md) | Pourquoi, pour qui, positionnement, principes, **standards de qualité mesurables** |
| [`CURRENT_STATE_AUDIT.md`](CURRENT_STATE_AUDIT.md) | Que vaut le code aujourd'hui ? État réel de chaque fonctionnalité, failles, bugs |
| [`COMPETITIVE_ANALYSIS.md`](COMPETITIVE_ANALYSIS.md) | Que font 1Panel, Coolify, Portainer, Cockpit, Webmin, Netdata, HestiaCP, Plesk, CloudPanel et d'autres ? |
| [`FEATURE_CATALOG.md`](FEATURE_CATALOG.md) | Toutes les fonctionnalités envisageables, avec un identifiant stable (`NET-02`…) |
| [`FEATURE_GAP_ANALYSIS.md`](FEATURE_GAP_ANALYSIS.md) | Ce qui manque, avec importance, complexité, priorité ; ce qui doit être une extension |
| [`TARGET_ARCHITECTURE.md`](TARGET_ARCHITECTURE.md) | Central + agent, catalogue d'opérations, jobs, événements, modèle de données, API, extensions |
| [`SECURITY_ROADMAP.md`](SECURITY_ROADMAP.md) | Modèle de menace, correctifs immédiats, exigences, niveaux de risque, tests automatisés |
| [`PRODUCT_ROADMAP.md`](PRODUCT_ROADMAP.md) | Les phases, dans quel ordre et pourquoi |
| [`TECHNICAL_DECISIONS.md`](TECHNICAL_DECISIONS.md) | Les choix structurants, leurs alternatives, et ceux qui attendent une décision |

---

## 1. Hostkeep aujourd'hui, en une minute

- **Ce qu'il est :** un panel mono-serveur natif (PHP, MariaDB, Bash, systemd), mobile-first, avec un vrai modèle de sécurité (web sans root, actions en liste blanche revalidées en root, DB sans mot de passe, CSP stricte, TOTP).
- **Ses forces :** sécurité de conception, cohabitation avec la configuration existante, légèreté, interface mobile, installateur sûr (récapitulatif, test, rollback), sauvegardes avec copie externe chiffrée, audit actionnable et réversible.
- **Ses faiblesses :** il **observe** beaucoup mais **gère** peu (pas de pare-feu, d'utilisateurs, de Docker, de création de site ou de base, de fichiers en écriture) ; **mono-serveur** par construction ; **aucun test** au-delà du lint ; français en dur.
- **Ses risques immédiats :** 2 brèches dans la frontière de privilège (rclone configurable exécuté en root ; un Administrateur peut obtenir les secrets du Super Admin), mises à jour non signées, notifications en double, journal d'audit incomplet.

## 2. Les 10 constats qui comptent

| # | Constat | Gravité | Où |
|---|---|---|---|
| 1 | Configuration rclone libre exécutée par le collecteur root → exécution de code root hors liste blanche (S1) | Élevée | Audit §5 |
| 2 | Un Administrateur lit `HOSTKEEP_APP_KEY` et télécharge le dump `hostkeep` → secrets du Super Admin (S2) | Moyenne | Audit §5 |
| 3 | « Mettre à jour » exécute en root le HEAD d'une branche, sans signature (S3) | Moyenne | Audit §5 |
| 4 | Sortie des tâches lisible par tout compte (S4) ; `X-Forwarded-For` trop permissif en mode proxy (S5) | Faible–moyenne | Audit §5 |
| 5 | Notifications renvoyées à chaque cycle quand le texte contient une valeur (B2) | Moyenne | Audit §6 |
| 6 | Résultat d'une action absent du journal si personne ne suit la tâche (B1) | Moyenne | Audit §6 |
| 7 | Liste blanche définie 4 fois (PHP ×2, JS, Bash) | Dette | Audit §3 |
| 8 | Aucun test unitaire, d'intégration, E2E ni de sécurité | Dette critique | Audit §7 |
| 9 | Mono-serveur codé en dur (chemins, tables, actions) | Bloquant pour la vision | Audit §11 |
| 10 | Le collecteur et le helper sont, de fait, un **agent local** : la cible multi-serveurs est une extraction, pas une refonte | Opportunité | Architecture §14 |

## 3. La cible, en une minute

- **Un serveur central** (l'application PHP actuelle, structurée) : interface, API `/api/v1`, comptes, rôles à portée, organisations, catalogue d'opérations, file de jobs, événements, alertes, incidents. **Sans aucun privilège.**
- **Un agent par serveur** (Go) : processus non root qui collecte et dialogue **en sortie** avec le central (mTLS, requêtes signées) + exécuteur root local, activé à la demande, qui n'exécute que les opérations qu'il connaît et que sa **politique locale** autorise.
- **Un catalogue d'opérations** : chaque action déclarée une fois (paramètres, permission, niveau de risque, confirmation, aperçu, réversibilité) ; il alimente l'interface, l'API, l'audit, les automatisations et l'IA.
- **Le mono-serveur reste** le cas par défaut : central + agent local, installés par la même commande.

## 4. Feuille de route

| Phase | Version | Contenu | Durée indicative |
|---|---|---|---|
| 0 | 2.10 | Correctifs sécurité, releases signées, premiers tests | 2–3 sem. |
| 1 | 3.0 | Fondations : migrations, modules, catalogue, jobs en base, événements, API v1, i18n, passkeys, tests | 6–8 sem. |
| 2 | 3.1–3.2 | Agent local (PHP sans sudo), puis agents distants et vue flotte | 8–10 sem. |
| 3 | 3.3 | Hôte : pare-feu, SSH, utilisateurs, services, tâches, mises à jour | 6–8 sem. |
| 4 | 3.4–3.6 | Sites et PHP par site, fichiers, Docker/Compose, déploiement Git, bases, sauvegardes restic | 12–16 sem. |
| 5 | 3.7 | Alertes configurables, incidents, Web Push, rapports | 6–8 sem. |
| 6 | 4.0 | Organisations, équipes, rôles personnalisés, API publique, extensions, audit externe | 8–10 sem. |
| 7 | 4.x | Workflows, remédiation, IA (extension facultative) | 8+ sem. |

Total indicatif jusqu'à la 4.0 : **12 à 16 mois** à temps plein pour une personne. Les contributions externes, rendues possibles par la Phase 1 (tests, modules, documentation), peuvent réduire ce délai.

---

## 5. Décisions qui attendent ton avis

Elles conditionnent le démarrage de la Phase 1 (détails, avantages et inconvénients dans `TECHNICAL_DECISIONS.md`) :

| ID | Question | Recommandation |
|---|---|---|
| **D-12** | Fondations d'abord, ou fonctionnalités visibles d'abord ? | Fondations d'abord, avec le pare-feu UFW comme premier cas réel du catalogue |
| **D-03** | Langage de l'agent | Go |
| **D-04** | Transport agent ↔ central | Sondage HTTPS sortant, WebSocket plus tard ; jamais d'agent qui écoute un port, jamais de SSH root depuis le central |
| **D-05** | Autoriser Composer et quelques bibliothèques (WebAuthn, JSON Schema), embarquées dans la release | Oui, liste blanche courte |
| **D-08** | Front : rester vanilla (modules ES) ou adopter un framework | Vanilla + modules ES, réévalué en Phase 4 |
| **D-15** | Licence : rester MIT ou passer en AGPL avant les contributions externes | MIT, sauf projet de financement |

Les autres décisions (D-01, D-02, D-06, D-07, D-09, D-10, D-11, D-13, D-14, D-16) sont des recommandations fortes qui peuvent être validées en bloc.

---

## 6. Prochaines étapes concrètes (Phase 0)

Ordre conseillé, chaque ligne = une branche / une PR :

1. **`fix(security): rclone`** — liste blanche des clés rclone et refus des backends dangereux (S1), tests unitaires.
2. **`fix(security): admin-boundary`** — racines et motifs interdits dans l'explorateur, dump `hostkeep` réservé au Super Admin (S2), tests.
3. **`fix(security): job-access`** — auteur et permission sur la lecture des jobs, identifiants aléatoires (S4).
4. **`fix(install): trusted-proxy`** — `set_real_ip_from` limité au proxy réel (S5).
5. **`fix(notify): stable-alert-keys`** — clés d'alerte indépendantes du texte (B2).
6. **`fix(audit): job-result`** — résultat des jobs toujours enregistré (B1).
7. **`ci: php-matrix + bats + phpunit + gitleaks`** — matrice 8.1–8.4, tests du helper, premiers tests PHP.
8. **`feat(release): signed-tags`** — releases signées et vérification dans `self-update` (S3), avec transition depuis la branche.
9. **`docs: SECURITY.md, CONTRIBUTING.md, tools/mock.py`** — politique de divulgation, guide contributeur, serveur de démo versionné.
10. **Mesures de référence** — RAM/CPU au repos et en charge, durée de collecte, Lighthouse : pour fixer les bases des standards de qualité.

Puis ouvrir la Phase 1 par la conception détaillée du **format du catalogue d'opérations** (un ADR dédié), en convertissant les 21 verbes actuels.

---

## 7. Comment utiliser ces documents dans les prochaines sessions

- **Commencer chaque session** par ce fichier, puis le document de la phase en cours.
- **Référencer les identifiants** : fonctionnalités (`WEB-02`), constats (`S1`, `B2`), décisions (`D-04`) dans les branches, commits, PR et commentaires.
- **Mettre à jour** :
  - l'état des fonctionnalités dans `FEATURE_GAP_ANALYSIS.md` quand elles sont livrées ;
  - `TECHNICAL_DECISIONS.md` quand une décision est prise ou change ;
  - ce plan à la fin de chaque phase (constats résolus, prochaine phase).
- **Distinguer** toujours fait vérifié, hypothèse et recommandation, comme dans l'audit.

## 8. Ce qui n'a pas pu être vérifié

- Aucune installation réelle ni mesure : performances et consommation sont **estimées** (audit §9 et §12).
- L'exploitabilité exacte de S1 dépend de la version de rclone de chaque distribution : à confirmer sur Debian 12/13 et Ubuntu 22.04/24.04.
- L'analyse concurrentielle s'appuie sur la documentation publique connue à mi-2026, sans installation des produits ; les points marqués « à revérifier » doivent l'être avant toute communication publique.
- Le dossier non suivi `admin-dashboard/` a été lu mais ni modifié ni supprimé.
