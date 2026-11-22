# Vision produit

## 1. Mission

> **Permettre à chacun — du particulier qui loue son premier VPS à l'équipe qui gère une flotte — d'administrer ses serveurs Linux en toute sécurité, sans terminal, depuis n'importe quel appareil.**

## 2. Ce que Hostkeep est aujourd'hui, ce qu'il doit devenir

| Aujourd'hui (2.9) | Demain (4.x) |
|---|---|
| Un serveur | Une flotte, organisée en groupes et en organisations |
| Surtout observer (et quelques actions) | Observer **et** gérer : hôte, sites, conteneurs, bases, sauvegardes, réseau, sécurité |
| Actions codées en dur | Catalogue d'opérations typées, prévisualisables, réversibles, accessibles par l'API |
| 4 rôles globaux | Rôles personnalisés à portée (organisation, groupe, serveur, ressource) |
| Français | Multilingue (français et anglais au minimum) |
| Sauvegardes complètes quotidiennes | Sauvegardes incrémentales chiffrées, vérifiées, restaurables depuis le panel |
| Alertes fixes | Règles configurables, incidents, Web Push, rapports |

## 3. Pour qui

| Profil | Besoin principal | Ce que Hostkeep apporte |
|---|---|---|
| **Particulier / homelab** (P) | Héberger quelques services sans devenir administrateur système | Assistant de sécurisation, sauvegardes simples, alertes sur le téléphone, applications en un clic (extension) |
| **Freelance / agence web** (F) | Gérer 5 à 50 sites clients sur quelques VPS | Création de sites, PHP par site, déploiement Git, certificats, sauvegardes par site, accès SFTP client |
| **Équipe IT / PME** (E) | Maintenir 10 à 200 serveurs proprement, à plusieurs | Flotte, opérations groupées, mises à jour planifiées, audit, rôles, API, rapports |
| **Hébergeur / prestataire** (H) | Séparer les clients, déléguer | Organisations, multi-clients (extension), journal d'audit, marque légère |

Le **particulier** reste la référence de simplicité : si une fonctionnalité n'est pas compréhensible par lui, elle est cachée derrière un mode avancé, pas imposée.

## 4. Positionnement

> **Hostkeep : administrer ses serveurs Linux — hôte, sites, conteneurs et sauvegardes — depuis une seule interface, sans jamais donner de shell root au web, en cohabitant avec ce qui existe, et jusque sur son téléphone.**

- **Face à Cockpit / Webmin :** même profondeur sur l'hôte, plus l'hébergement, les sauvegardes, le multi-serveurs et une interface moderne.
- **Face à Portainer / Coolify :** les conteneurs **et** l'hôte, sans exiger que tout soit conteneurisé ni donner une clé root SSH au serveur central.
- **Face à HestiaCP / CloudPanel / Plesk :** s'installe sur un serveur existant sans le reconfigurer, open source intégral, multi-serveurs.
- **Face à Netdata / Uptime Kuma :** moins fin sur les métriques, mais on **agit** depuis la même interface ; intégration plutôt que concurrence.

Analyse complète : `COMPETITIVE_ANALYSIS.md`.

## 5. Principes de conception

1. **Sécurité par construction.** Jamais de shell libre ; chaque modification est une opération typée, vérifiée deux fois, journalisée, au moindre privilège.
2. **Voir avant d'agir.** Chaque opération qui change quelque chose montre ses effets (fichiers, services, diff) avant exécution, et dit si elle est réversible.
3. **Réversible par défaut.** Copie de sécurité, test de configuration, retour arrière automatique, *confirm-or-revert* pour le réseau.
4. **Cohabiter, ne pas conquérir.** Lire l'existant, ne gérer que ce qu'on a créé ou ce que l'utilisateur adopte explicitement.
5. **Mobile d'abord.** Toute opération courante doit être faisable au téléphone, d'une main.
6. **Léger.** Rien qui tourne au repos sans nécessité ; quelques dizaines de Mo au total.
7. **Expliquer.** Chaque alerte dit pourquoi elle existe et quoi faire ; chaque erreur propose une piste.
8. **Ouvert.** Cœur entièrement libre, API documentée, formats lisibles (fichiers de configuration natifs, sauvegardes restic standard).

## 6. Ce que Hostkeep ne fera pas (non-objectifs)

- Serveur mail complet, serveur DNS faisant autorité, orchestration Kubernetes, facturation d'hébergement.
- Exiger un serveur vierge ou prendre le contrôle exclusif du serveur web.
- Exécuter des commandes shell libres depuis l'interface, l'API ou l'IA.
- Réserver la sécurité, le multi-serveurs ou les équipes à une édition payante.

## 7. Objectifs produit mesurables

| Objectif | Indicateur | Cible |
|---|---|---|
| Installation sans friction | Temps entre la commande d'installation et le premier écran | < 5 min sur un VPS 1 vCPU / 1 Go |
| Sécuriser un VPS neuf sans SSH | Pare-feu + SSH par clé + mises à jour auto + 2FA, au téléphone | < 10 min (à partir de la 3.3) |
| Mettre un site en ligne | Site PHP depuis Git, avec base et HTTPS | < 5 min (à partir de la 3.5) |
| Restaurer | Restauration d'un site complet testée automatiquement | Chaque semaine (à partir de la 3.6) |
| Fiabilité perçue | Alertes en double ou fausses alertes signalées | 0 doublon ; < 1 fausse alerte / serveur / mois |

---

## 8. Standards de qualité

Chaque standard est mesurable et vérifié en CI ou à chaque release. Les valeurs sont des **cibles** ; celles marquées « mesurer » doivent d'abord être relevées sur la version actuelle pour fixer une base.

### 8.1 Performance
| Indicateur | Cible | Mesure |
|---|---|---|
| Temps de réponse API en lecture | p95 < 150 ms, p99 < 400 ms (central avec 20 serveurs) | Test de charge k6 en CI nocturne |
| Premier affichage utile sur mobile 4G simulée | < 1,5 s | Lighthouse CI |
| Poids du front (JS + CSS, compressés) | ≤ 150 Ko au premier chargement | CI (budget) |
| Durée d'un cycle de collecte « minute » | < 2 s | Mesure dans l'agent, alerte si dépassé |
| Fraîcheur des données | Âge max d'un snapshot < 2 × sa période | Alerte « données anciennes » |

### 8.2 Consommation de ressources
| Composant | RAM | CPU |
|---|---|---|
| Central au repos (hors MariaDB) | ≤ 60 Mo (PSS) | ≈ 0 % |
| Central, 100 serveurs (hors MariaDB) | ≤ 300 Mo | ≤ 10 % d'un vCPU en moyenne |
| Agent | ≤ 25 Mo RSS | ≤ 1 % d'un vCPU en moyenne |
| Exécuteur au repos | 0 (activation par socket) | 0 |
| Aujourd'hui (2.9) | **mesurer** | **mesurer** |

### 8.3 Disponibilité et fiabilité
| Indicateur | Cible |
|---|---|
| Redémarrage du service central | < 5 s, sans perte de job (reprise) |
| Minutes de métriques perdues | < 0,5 % par mois et par serveur |
| Délai détection → notification (alertes critiques) | < 60 s |
| Mise à jour du central | Sans interruption > 10 s ; retour arrière automatique si le contrôle de santé échoue |
| Mise à jour des agents | Retour automatique si pas de reconnexion en 2 min |

### 8.4 Sécurité
| Indicateur | Cible |
|---|---|
| Constats High ouverts à la release (SAST, ZAP, dépendances) | 0 |
| Délai de correctif d'une faille critique | ≤ 7 jours |
| Releases et binaires signés | 100 % |
| Opérations couvertes par des tests de validation (valide / invalide / injection) | 100 % |
| Routes couvertes par la matrice RBAC et d'accès croisé | 100 % |
| `systemd-analyze security` des services Hostkeep | Score ≤ 3.0 |
| Audit externe | Avant 4.0, puis tous les 2 ans |

### 8.5 Tests
| Indicateur | Cible |
|---|---|
| Couverture de lignes PHP (`Domain`, `Security`, `Operations`) | ≥ 80 % (≥ 60 % en fin de Phase 1) |
| Couverture de l'agent Go | ≥ 75 % |
| Parcours E2E critiques (connexion, 2FA, action, sauvegarde, restauration, enrôlement) | 100 % automatisés |
| Tests d'installation | Chaque release, sur toutes les distributions supportées |

### 8.6 Compatibilité
| Indicateur | Cible |
|---|---|
| Distributions | Debian 12/13, Ubuntu 22.04/24.04 (+ LTS suivantes) ; RHEL/Alma/Rocky 9+ à partir de la Phase 4 ou plus tard |
| Architectures | amd64 et arm64, testées à chaque release |
| PHP (central) | Toutes les versions des distributions supportées (8.1 → 8.4 aujourd'hui) |
| Navigateurs | Deux dernières versions de Chrome, Firefox, Safari (iOS compris), Edge |
| Agents | Version N et N-1 acceptées par le central |

### 8.7 Accessibilité
| Indicateur | Cible |
|---|---|
| Conformité | WCAG 2.2 niveau AA |
| Score Lighthouse Accessibilité | ≥ 95 sur chaque page |
| Violations axe-core (sérieuses / critiques) | 0 en CI |
| Navigation clavier | 100 % des actions atteignables, focus visible |
| Lecteur d'écran | Parcours principaux vérifiés manuellement à chaque version mineure |

### 8.8 Expérience mobile
| Indicateur | Cible |
|---|---|
| Largeur minimale supportée | 360 px sans défilement horizontal |
| Cibles tactiles | ≥ 44 × 44 px |
| Opérations courantes faisables au téléphone | 100 % (seules l'édition de fichiers longs et la configuration avancée peuvent être « confort sur ordinateur ») |
| Notifications push | Disponibles sur Android et iOS (PWA installée) |

### 8.9 Documentation
| Indicateur | Cible |
|---|---|
| Fonctionnalité livrée avec sa documentation utilisateur | 100 % |
| Opérations documentées (effets, risques, réversibilité) | 100 %, générées depuis le catalogue |
| API | OpenAPI publiée, exemples pour chaque route |
| Décisions structurantes | Une entrée dans `TECHNICAL_DECISIONS.md` |
| Guide contributeur, `SECURITY.md`, architecture | Présents et à jour à chaque version mineure |

### 8.10 Maintenabilité
| Indicateur | Cible |
|---|---|
| PHPStan | Niveau 8 sans baseline croissante |
| Taille des fichiers | ≤ 600 lignes (hors données générées) |
| Duplication de règles (permissions, validations) | 0 : une seule source (le catalogue) |
| Délai de revue d'une contribution externe | ≤ 7 jours |
| Changelog | Chaque version, format Keep a Changelog |
