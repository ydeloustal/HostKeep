# Analyse des écarts

> Compare l'état réel de Hostkeep **2.10.1** (`CURRENT_STATE_AUDIT.md`, §0 pour le statut des constats) avec le catalogue cible (`FEATURE_CATALOG.md`).
> Les fonctionnalités déjà correctement implémentées ne sont **pas** reproposées ; elles apparaissent seulement quand une extension de périmètre est utile.

**Échelles**
- **Importance** : Haute (promesse du produit) · Moyenne · Basse.
- **Complexité** : S (≤ 3 j) · M (1–2 sem.) · L (3–6 sem.) · XL (> 6 sem.), pour un développeur à temps plein connaissant le code. Estimations indicatives.
- **Priorité** : **P0** immédiat (sécurité/correctifs) · **P1** prochaine phase · **P2** phase suivante · **P3** plus tard · **—** ne pas faire dans le cœur.
- Les phases citées renvoient à `PRODUCT_ROADMAP.md`.

---

## 1. Correctifs de l'existant (avant toute nouveauté)

| Élément | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| S1 — rclone root avec configuration libre | ✅ corrigé en 2.10.0 (reste : rclone non root, P1) | Haute | S | fait | Liste blanche de clés par backend, refus des options exécutables, rclone sous un utilisateur non root |
| S2 — Admin lit `APP_KEY` et le dump `hostkeep` | ✅ corrigé en 2.10.0 | Haute | S | fait | Racine Hostkeep interdite à l'explorateur, motifs sensibles refusés, base `hostkeep` réservée au Super Admin |
| S3 — Mise à jour non signée | 🟡 tags signés livrés en 2.10.0, **inactifs sans clé** ; archives signées en P1 | Haute | M | fait / **action mainteneur** | Releases signées sur tags, vérification avant installation |
| S4 — Sortie des tâches lisible par tous | ✅ corrigé en 2.10.0 | Moyenne | S | fait | Auteur + permission + identifiant aléatoire |
| S5 — `X-Forwarded-For` de tous les réseaux privés | ✅ corrigé en 2.10.0 | Moyenne | S | fait | Proxy de confiance explicite |
| S6 — Journal d'audit modifiable | 🟡 | Moyenne | M | P1 | Table de résultats séparée, INSERT seul, chaînage |
| S7 — Dumps SQL lisibles par le web | 🟡 | Moyenne | S | P1 | Passage par le helper, `root:root 600` |
| B1 — `rc` absent du journal si personne ne suit la tâche | ✅ corrigé en 2.10.0 | Moyenne | S | fait | Le helper écrit le résultat (ou le collecteur le réconcilie) |
| B2 — Notifications répétées (texte variable) | ✅ corrigé en 2.10.0 | Moyenne | S | fait | Clé d'alerte stable (`type:ressource`), texte séparé |
| B4 — Collecte séquentielle des sites | 🟡 | Moyenne | M | P1 | Sondes parallèles (curl multi), budget de temps, alerte « données anciennes » |
| B5 — Un seul disque dans l'historique | 🟡 | Moyenne | S | P1 | Table `metrics_disk` par point de montage |
| B6 — PHP 8.1 non testé | ✅ corrigé en 2.10.0 | Moyenne | S | fait | Matrice CI 8.1 → 8.4 |
| B9, B10 — installateur (DNS, `grep -q` + `pipefail`) | ✅ corrigés en 2.10.1 | Moyenne | S | fait | Trouvés par l'installation réelle |
| Tests automatisés | 🟡 socle en 2.10 (PHP, helper, intégration hors CI) | Haute | L | **P1** | Voir `SECURITY_ROADMAP.md` §7 et roadmap Phase 1 |

### 1 bis. Écarts liés à la vision « produit public » (🌍)

| Élément | État actuel (2.10.1) | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| Installation vérifiée (archive signée) | ⚪ `curl … \| bash` depuis `main`, non vérifié | Haute | M | P1 | D-11 |
| Dépôt APT, image Docker du central | ⚪ | Moyenne | M | P2 | D-11, D-17 |
| Interface et doc en anglais | ⚪ tout en français | Haute | M + continu | P1 | D-18, i18n UI-11 |
| Site de documentation (guides + référence) | ⚪ README seulement | Haute | M + continu | P1 | `TARGET_ARCHITECTURE.md` §15 (L10) |
| Politique de versions et de support | ⚪ | Haute | S | P1 | D-19 |
| Cadre de contribution (DCO, code de conduite, modèles) | 🟡 `CONTRIBUTING.md` | Moyenne | S | P1 | D-20 |
| Réglages par organisation et par serveur | ⚪ réglages globaux | Haute | M | P1 (modèle) | L2 |
| Tests d'accès croisé entre organisations | ⚪ | Haute | M | P1 | L3, sécurité §3.5 |
| API publique (jetons à portée, OpenAPI) | ⚪ | Haute | L | P2 (3.2) | D-22 |
| Sauvegarde et reprise du central | 🟡 archive de migration | Haute | M | P2 | L11 |
| Audit de sécurité externe | ⚪ | Haute | — (budget) | Fin P2 | L9 |

---

## 2. Matrice des écarts fonctionnels

### A. Infrastructure
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| INF-01/02 Multi-serveurs + agent | ⚪ | Haute | XL | P1 (fondations) / P2 (livraison) | Agent à connexion sortante ; voir `TARGET_ARCHITECTURE.md` |
| INF-03 Inventaire matériel | ⚪ | Moyenne | S | P2 | Collecte unique au démarrage de l'agent + quotidienne |
| INF-04 Distribution / fin de support | 🟡 (noyau seulement) | Moyenne | S | P2 | Table des dates EOL embarquée, alerte 90 j avant |
| INF-05 Services systemd complets | 🟡 | Haute | M | P1 | Passer par D-Bus/`systemctl show` pour toutes les unités ; enable/disable ; journal par unité ; liste protégée (ssh, hostkeep-agent) |
| INF-06 Processus (signaux) | 🟡 | Moyenne | S | P2 | TERM puis KILL avec confirmation ; interdits : PID 1, sshd, agent |
| INF-07 Limites de ressources | ⚪ | Basse | M | P3 | Drop-ins systemd générés |
| INF-08 Stockage avancé | 🟡 | Moyenne | M | P2 (lecture) | SMART, inodes, par partition ; écriture LVM/RAID en extension |
| INF-09 Utilisateurs Linux | ⚪ | Haute | M | P1 | Création/verrouillage/sudo avec garde-fous (jamais root, jamais le compte qui sert à se connecter sans avertissement) |
| INF-10 Permissions fichiers | ⚪ | Moyenne | S | P2 | Avec FIL-05 |
| INF-11 Tâches planifiées | 🟡 (lecture) | Haute | M | P1 | Écrire des timers systemd générés (lisibles, journalisés) plutôt que des crontabs ; crontabs en édition simple |
| INF-12 Mises à jour avancées | 🟡 | Haute | M | P1 | Sélection, simulation (`apt-get -s`), unattended-upgrades, fenêtre de maintenance, historique |
| INF-13 Redémarrage planifié | ⚪ | Moyenne | S | P2 | Timer transitoire + vérification au retour |
| INF-14 Paquets | ⚪ | Basse | S | P3 | Liste blanche configurable |
| INF-16 RHEL/Alma/Rocky, ARM64 | ⚪ / ❓ | Moyenne | L | P3 | Après l'agent (abstraction de distribution dans l'agent) |

### B. Hébergement
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| WEB-02/03 Création de sites + modèles | ⚪ | Haute | L | P2 | Modèles versionnés par serveur web × type ; test + rollback (déjà maîtrisés) |
| WEB-04 Édition de vhost | ⚪ | Moyenne | M | P2 | Éditeur + `nginx -t`/`configtest` + diff + rollback |
| WEB-05 Alias/redirections | ⚪ | Moyenne | S | P2 | Dans les modèles |
| WEB-06 Émission de certificats (dont DNS-01) | 🟡 (renouvellement) | Haute | M | P2 | certbot HTTP-01 d'abord ; DNS-01 via extensions DNS |
| WEB-07 PHP multi-versions / pools par site | 🟡 (lecture) | Haute | L | P2 | Dépôt Sury sur Debian/Ubuntu, pool par site |
| WEB-08 Isolation par site | ⚪ | Moyenne | M | P2 | Utilisateur Linux par site créé par l'assistant |
| WEB-09/10/11 Node, Python, applis systemd | 🟡 (PM2 lecture) | Moyenne | M | P2 | Unités systemd générées ; PM2 conservé en lecture/redémarrage |
| WEB-12/13/14 Déploiement Git + webhooks | ⚪ | Haute | L | P2 | Releases atomiques par lien symbolique (`releases/<id>`, `current`) |
| WEB-15 Variables d'environnement | ⚪ | Haute | M | P2 | Coffre de secrets (SEC-14) |
| WEB-16 Rollback de déploiement | ⚪ | Haute | S (avec WEB-12) | P2 | Bascule de lien symbolique |
| WEB-17 Environnements | ⚪ | Moyenne | M | P3 | — |
| WEB-18/19 Installeurs, WordPress | ⚪ | Moyenne | M | P3 | **Extension** |

### C. Conteneurs
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| CTR-01 Installation Docker | ⚪ | Haute | S | P2 | Dépôt officiel Docker |
| CTR-02/03/04/07 Conteneurs, images, volumes, journaux | ⚪ | Haute | L | P2 | Via l'API Docker sur socket, **depuis l'agent uniquement** (le socket Docker = root) |
| CTR-06 Compose / stacks | ⚪ | Haute | L | P2 | Fichiers dans `/srv/hostkeep/stacks/<nom>/`, `docker compose` piloté par l'agent |
| CTR-08 Métriques par conteneur | ⚪ | Moyenne | M | P2 | cgroups v2 |
| CTR-09 Registres | ⚪ | Moyenne | S | P3 | Secrets |
| CTR-10 Catalogue d'applications | ⚪ | Moyenne | L | P3 | **Extension** + dépôt de modèles signé |
| CTR-11 Mises à jour d'images | ⚪ | Moyenne | M | P3 | Comparaison de digests |
| CTR-12 Exposition web d'un conteneur | ⚪ | Haute | M | P2 | Réutilise WEB-02 (reverse proxy) |

### D. Bases de données
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| DB-01/02 MariaDB : bases, utilisateurs, droits | 🟡 (lecture) | Haute | M | P2 | Mots de passe générés, affichés une fois, stockables dans le coffre |
| DB-03 PostgreSQL | ⚪ | Moyenne | M | P3 | Même modèle d'opérations |
| DB-06 Monitoring | 🟡 | Moyenne | M | P3 | — |
| DB-07 Import / export | 🟡 (export par sauvegarde) | Haute | M | P2 | Upload par morceaux, import dans une base neuve par défaut |
| DB-08 Sauvegarde par base | 🟡 (toutes ensemble) | Haute | M | P2 | Politiques BAK-05 |
| DB-09 Restauration vers une autre base | 🟡 | Moyenne | S | P2 | Option « nouvelle base » |
| DB-10 Requêtes lentes | 🟡 (compteur) | Moyenne | M | P3 | — |
| DB-04/05/11/12 Redis, MongoDB, tuning, client SQL | ⚪ | Basse | M | P3 | **Extensions** |

### E. Réseau
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| NET-01 Ports en écoute | ✅ (lecture) | — | — | — | Ajouter le lien vers le pare-feu (P1) |
| NET-02 UFW | ⚪ | Haute | M | **P1** | Avec NET-04 obligatoire |
| NET-03 nftables / firewalld | ⚪ | Moyenne | L | P3 | Backend abstrait dans l'agent |
| NET-04 Anti-verrouillage (confirm-or-revert) | ⚪ | Haute | M | **P1** | Indispensable avant toute règle réseau ou SSH |
| NET-05 Interfaces | ⚪ | Basse | S | P2 | Lecture |
| NET-06 DNS fournisseurs | ⚪ | Moyenne | M par fournisseur | P3 | **Extensions** |
| NET-08 Vérification DNS | 🟡 (installateur) | Moyenne | S | P2 | — |
| NET-09/10 Connexions, trafic par interface | ⚪ | Basse | S | P3 | — |
| NET-11 WireGuard | ⚪ | Basse | M | P3 | **Extension** |

### F. Sécurité
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| SEC-02 Corrections réversibles étendues | 🟡 (3 corrections) | Haute | M | P1 | SSH, sysctl, unattended-upgrades, permissions |
| SEC-03 CVE des paquets | ⚪ | Moyenne | M | P3 | debsecan / OSV |
| SEC-05 Configuration SSH | ⚪ | Haute | M | **P1** | `sshd -t` + confirm-or-revert |
| SEC-06 Clés SSH | ⚪ | Haute | S | **P1** | — |
| SEC-07 Fail2ban complet | 🟡 | Moyenne | S | P2 | Bannir manuellement, seuils |
| SEC-08 Connexions | 🟡 | Moyenne | S | P2 | Refusées, sudo, nouvelles IP |
| SEC-11 Passkeys | ⚪ | Haute | M | **P1** | Bibliothèque WebAuthn éprouvée (voir D-05) |
| SEC-12 OIDC | ⚪ | Moyenne | M | P3 | — |
| SEC-13 Audit infalsifiable | 🟠 | Haute | M | P1 | Voir S6 |
| SEC-14 Coffre de secrets | 🟡 (AES-GCM, clé unique) | Haute | M | P1 | Chiffrement par enveloppe, rotation |
| SEC-15 Anomalies (règles) | ⚪ | Moyenne | M | P3 | — |
| SEC-17 Recommandations priorisées | 🟡 (audit) | Haute | M | P2 | Page « À faire » unifiée |
| SEC-19/20 Conformité, antivirus | ⚪ | Basse | L | — | **Extensions** |

### G. Monitoring
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| MON-02 Disques par partition, I/O | 🟡 | Haute | S | P1 | Voir B5 |
| MON-03 Réseau par interface | 🟡 | Basse | S | P2 | — |
| MON-04 Températures | ⚪ | Basse | S | P3 | `/sys/class/hwmon` |
| MON-09 Moniteurs configurables | ⚪ | Moyenne | M | P2 | — |
| MON-11 Alertes personnalisables | 🟡 (17 règles fixes) | Haute | L | P2 | Moteur de règles simple ; règles actuelles = règles par défaut |
| MON-12 Incidents | ⚪ | Haute | M | P2 | Table `incidents` alimentée par le moteur d'alertes |
| MON-14 Comparaison serveurs | ⚪ | Moyenne | M | P3 | Après multi-serveurs |
| MON-15 Rapports périodiques | ⚪ | Moyenne | M | P3 | — |
| MON-17/18 Prometheus, Netdata | ⚪ | Basse | M | P3 | **Extensions** |
| MON-20 Web Push | ⚪ | Haute | M | P2 | VAPID, sans service tiers |

### H. Sauvegardes
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| BAK-02 Incrémental dédupliqué | ⚪ | Haute | L | P2 | **restic** (voir D-09) |
| BAK-05 Politiques par ressource | ⚪ | Haute | L | P2 | — |
| BAK-06 Restauration sélective depuis le panel | 🟠 | Haute | M | P2 | Vers un emplacement à part par défaut |
| BAK-07 Restauration complète de site / serveur | 🟠 | Haute | L | P2 | — |
| BAK-08 Vérification d'intégrité | ⚪ (SHA calculé, jamais revérifié) | Haute | S | P1 | Revérification hebdomadaire locale et distante |
| BAK-09 Test de restauration | ⚪ | Moyenne | M | P3 | — |
| BAK-10 Rétention GFS | 🟡 (jours) | Moyenne | S | P2 | Natif avec restic |

### I. Fichiers
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| FIL-02 Éditeur | ⚪ | Haute | M | P2 | Éditeur léger (CodeMirror 6 auto-hébergé, ou textarea + coloration) ; copie de sauvegarde + diff |
| FIL-03 Upload | ⚪ | Haute | M | P2 | Par morceaux, quota, antivirus optionnel |
| FIL-05 Permissions | ⚪ | Moyenne | S | P2 | — |
| FIL-06 Recherche | ⚪ | Moyenne | S | P2 | — |
| FIL-07 Compression | ⚪ | Moyenne | S | P2 | — |
| FIL-08 Opérations + corbeille | ⚪ | Haute | M | P2 | Corbeille par racine, purge 7 j |
| FIL-09 Transfert entre serveurs | ⚪ | Basse | M | P3 | — |

### J. Automatisation
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| AUT-07 API publique + jetons | 🟠 (session seulement) | Haute | L | **P1** (API v1 interne) / **P2** (publique, 3.2) | `/api/v1`, OpenAPI, jetons à portée (D-22) |
| AUT-09 Bus d'événements | ⚪ | Haute | M | **P1** | Table `events` + consommateurs |
| AUT-02 Planification d'opérations | 🟡 (sauvegarde) | Haute | M | P2 | — |
| AUT-05 Remédiation bornée | ⚪ | Haute | M | P2 | — |
| AUT-06 Webhooks signés | 🟡 (webhook d'alertes non signé) | Moyenne | S | P2 | HMAC |
| AUT-01/03/04/10 Scripts, workflows, déclencheurs | ⚪ | Moyenne | L | P3 | — |
| AUT-08 Canaux supplémentaires | 🟡 | Moyenne | S chacun | P2 | ntfy, Gotify, Slack, Matrix |

### K. Administration professionnelle
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| ORG-05 Permissions à portée | 🟠 (4 rôles globaux) | Haute | L | **P1 (modèle)** / P3 (UI) | Le modèle de données doit être prêt dès la Phase 1 |
| ORG-01/02 Organisations, équipes | ⚪ | Moyenne | L | P3 | — |
| ORG-04 Rôles personnalisés | ⚪ | Moyenne | M | P3 | — |
| ORG-06 Multi-clients | ⚪ | Basse | XL | P3 | **Extension « Hosting »** |
| ORG-10 Extensions | ⚪ | Moyenne | L | P3 | Après stabilisation du catalogue d'opérations |
| ORG-11 Opérations groupées | ⚪ | Haute | M | P2 | Dès le multi-serveurs |
| ORG-12 Quatre yeux | ⚪ | Basse | M | P3 | — |

### L. IA
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| AI-01 à AI-10 | ⚪ | Basse à moyenne | L | P3 | **Extension officielle**, après l'API et le catalogue d'opérations (sans eux, l'IA ne peut agir que par shell, ce qui est exclu) |

### M. Interface
| Fonctionnalité | État actuel | Importance | Complexité | Priorité | Recommandation |
|---|---|---|---|---|---|
| UI-11 i18n | ⚪ | Haute | M (+ coût continu) | **P1** | À faire **avant** d'ajouter des écrans |
| UI-07 Navigation multi-serveurs | ⚪ | Haute | M | P2 | — |
| UI-13 Progression des opérations | 🟡 (fenêtre + notification) | Haute | M | P1 | Centre de tâches persistant (lié à la file de jobs) |
| UI-15 Aperçu avant exécution | 🟠 (confirmation simple) | Haute | M | P1 | Chaque opération déclare ses effets (voir architecture) |
| UI-09 Assistant de démarrage | 🟠 | Haute | S | P2 | Checklist persistante |
| UI-08 Centre de notifications | ⚪ | Moyenne | M | P2 | — |
| UI-01 Dashboard personnalisable | ⚪ | Basse | M | P3 | — |
| UI-10 Aide contextuelle | 🟠 | Moyenne | M | P2 | — |
| UI-16 Accessibilité mesurée | 🟡 | Haute | S (outillage) | P1 | axe-core dans les tests E2E |
| UI-17 Terminal web | ⚪ (refus volontaire) | Basse | M | — | Extension désactivée par défaut, ou jamais |

---

## 3. Fiches détaillées des écarts majeurs

### 3.1 Multi-serveurs et agent (INF-01, INF-02)
- **Utilité :** gérer N serveurs depuis une interface ; condition de presque toute la vision.
- **Utilisateurs :** tous dès 2 serveurs ; indispensable pour E et H.
- **Valeur ajoutée :** vue de flotte, opérations groupées, comparaison, sauvegardes croisées.
- **Dépendances :** catalogue d'opérations typées, file de tâches en base, API interne, identité de serveur (`server_id` partout), PKI d'enrôlement.
- **Risques :** surface d'attaque (un serveur central compromis = flotte compromise) ; complexité réseau (NAT, pare-feu) ; compatibilité ascendante du mode mono-serveur.
- **Solutions :** agent à connexion sortante (mTLS) ; le serveur central ne peut demander **que** des opérations du catalogue ; politique locale de l'agent (opérations autorisées par serveur) ; mode « tout-en-un » conservé (central + agent local sur la même machine).
- **Maintenance :** élevée (deux composants versionnés, compatibilité de protocole).
- **Performance :** agent cible ≤ 25 Mo de RAM, ≤ 1 % CPU moyen ; central : ~1 requête/min/serveur en régime normal.

### 3.2 Catalogue d'opérations typées (transverse, prérequis)
- **Utilité :** une seule définition par opération (paramètres, validation, permission, risque, effets, réversibilité) qui sert à l'UI, l'API, l'agent, l'audit, l'IA.
- **Valeur :** supprime la liste blanche quadruplée (B7), rend chaque opération testable, documentable et prévisualisable.
- **Dépendances :** aucune ; c'est le premier chantier technique.
- **Risques :** sur-ingénierie si le format est trop générique ; le garder déclaratif et simple (JSON Schema + code d'exécution).
- **Maintenance :** baisse le coût de chaque fonctionnalité suivante.

### 3.3 Pare-feu et SSH (NET-02, NET-04, SEC-05, SEC-06)
- **Utilité :** les deux premiers gestes de sécurisation d'un VPS ; aujourd'hui documentés en commandes dans le README.
- **Utilisateurs :** tous, surtout débutants.
- **Risques :** **verrouillage** (perte d'accès SSH et panel). Exige le mécanisme « confirm-or-revert » : la règle est appliquée, un minuteur de 60 s la retire si l'utilisateur ne confirme pas depuis une nouvelle connexion.
- **Solutions :** UFW d'abord (présent sur Ubuntu, simple), nftables ensuite ; règles du panel et de SSH protégées.
- **Maintenance :** moyenne. **Performance :** nulle.

### 3.4 Création de sites et PHP par site (WEB-02, WEB-03, WEB-07, WEB-08)
- **Utilité :** passer de « je vois mes sites » à « je crée et gère mes sites ».
- **Utilisateurs :** P, F, H.
- **Dépendances :** modèles de configuration versionnés, utilisateurs Linux (INF-09), certificats (WEB-06), pools PHP-FPM.
- **Risques :** casser la configuration existante → déjà maîtrisé (test + rollback) ; conflits de noms de domaine.
- **Solutions :** fichiers générés marqués « géré par Hostkeep » ; les sites existants restent en lecture (ou « adoptables » explicitement).
- **Maintenance :** moyenne à élevée (3 serveurs web × N modèles) ; commencer par Nginx + PHP-FPM, puis Apache, puis Caddy.

### 3.5 Docker et Compose (CTR-01 à CTR-08, CTR-12)
- **Utilité :** l'essentiel des applications auto-hébergées se déploie en Compose.
- **Dépendances :** agent (l'accès au socket Docker équivaut à root : il ne doit **jamais** être donné au processus web).
- **Risques :** périmètre énorme ; ne pas réécrire Portainer. Viser : stacks Compose, conteneurs, journaux, mises à jour, exposition web.
- **Maintenance :** moyenne (API Docker stable).

### 3.6 Sauvegardes incrémentales et restauration complète (BAK-02, BAK-05 à BAK-08)
- **Utilité :** le format actuel (tar.gz complet quotidien) coûte cher en disque et en bande passante dès quelques Go.
- **Solutions :** restic (binaire unique, chiffré, dédupliqué, backends S3/SFTP/B2/REST/rclone, `check`, `restore --include`). Conserver le format actuel en option « archive simple ».
- **Risques :** migration des sauvegardes existantes (garder la lecture de l'ancien format) ; gestion de la clé du dépôt (coffre de secrets + copie hors serveur obligatoire, comme aujourd'hui).
- **Performance :** restic consomme de la RAM pendant les sauvegardes (indexation) : limiter via `GOMEMLIMIT`, `nice`, `ionice`.

### 3.7 API publique, événements, jetons (AUT-07, AUT-09, ORG-08)
- **Utilité :** automatisation, intégrations, CLI distante, IA, extensions.
- **Dépendances :** catalogue d'opérations, permissions à portée.
- **Risques :** jetons volés → portée minimale, expiration, restriction IP, journalisation.
- **Maintenance :** contrat versionné (`/api/v1`), tests de contrat.

### 3.8 Internationalisation (UI-11)
- **Utilité :** ouverture au-delà de la francophonie ; indispensable pour une « plateforme de référence ».
- **Coût :** chaque écran ajouté sans i18n alourdit la migration ; ~1 500 chaînes estimées aujourd'hui (PHP, JS, Bash).
- **Solution :** catalogues JSON par langue, clés stables, fonction `t()` côté PHP et JS ; messages du helper/agent renvoyés en **codes** + paramètres, traduits par l'interface.

### 3.9 Alertes configurables et incidents (MON-11, MON-12)
- **Utilité :** les 17 règles fixes ne s'adaptent pas (un serveur à 92 % de RAM en permanence crée du bruit).
- **Solutions :** règles déclaratives (métrique, opérateur, seuil, durée, portée, gravité), silences, fenêtres de maintenance ; chaque déclenchement crée un incident avec chronologie. Règles actuelles livrées comme règles par défaut. Corrige B2 structurellement.

---

## 4. Ce qui doit être une extension plutôt que le cœur

| Fonctionnalité | Pourquoi hors du cœur |
|---|---|
| Fournisseurs DNS (Cloudflare, OVH…) | Une intégration par API tierce, cycle de vie propre |
| Catalogue d'applications Docker | Contenu (modèles) qui évolue plus vite que le code ; dépôt séparé signé |
| Installeurs et outils WordPress | Spécifique à un CMS |
| Redis, MongoDB, client SQL | Usage minoritaire |
| Scan d'images (Trivy), antivirus, conformité CIS | Outils tiers lourds |
| WireGuard | Utile mais orthogonal |
| Export Prometheus / OTel, intégrations Netdata / Kuma | Écosystèmes externes |
| Pages de statut publiques | Exposition publique : surface différente |
| Multi-clients / « Hosting » (revendeurs, quotas) | Change le modèle de responsabilité, utile à une minorité (H) |
| Assistant IA | Facultatif par principe, dépend de fournisseurs externes |
| Terminal web, console de conteneur | Contraire au principe « pas de shell au web » : seulement en extension désactivée par défaut |

## 5. Ce qu'il ne faut pas faire

| Idée | Raison |
|---|---|
| Serveur mail complet (Postfix/Dovecot/antispam) dans le cœur | Coût de maintenance et de délivrabilité disproportionné ; services spécialisés meilleurs |
| Serveur DNS faisant autorité | Rarement utile sur un VPS ; les fournisseurs DNS le font mieux |
| Orchestration Kubernetes / Swarm | Autre produit (Rancher, Portainer) |
| Facturation / revendeurs | Intégrations via API plutôt que réimplémentation |
| Exiger un serveur vierge | Contraire au différenciateur « cohabitation » |
| Exécution de commandes shell libres depuis l'interface, l'API ou l'IA | Contraire au modèle de sécurité ; les scripts enregistrés (AUT-01) restent une permission dédiée, auditée, désactivable |
