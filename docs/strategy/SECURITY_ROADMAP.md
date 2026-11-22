# Feuille de route sécurité

> La sécurité est la **première** promesse de Hostkeep : un outil qui administre des serveurs est, par définition, une cible de choix. Ce document fixe les exigences (non négociables), les correctifs immédiats et les contrôles automatisés.
> Les identifiants S1…S8 et B1…B8 renvoient à `CURRENT_STATE_AUDIT.md`.

---

## 1. Modèle de menace

### 1.1 Biens à protéger
1. Le contrôle **root** des serveurs gérés.
2. Les **secrets** : clés de sauvegarde, identifiants S3/SFTP/SMTP, jetons, variables d'environnement d'applications, secrets TOTP.
3. Les **données** des sites et bases (sauvegardes incluses).
4. L'**intégrité** du journal d'audit et des releases.
5. La **disponibilité** de l'accès (ne jamais verrouiller l'administrateur hors de son serveur).

### 1.2 Acteurs et capacités
| Acteur | Peut | Ne doit jamais pouvoir |
|---|---|---|
| Internet anonyme | Atteindre la page de connexion, l'API (401), la passerelle agents (mTLS) | Lire une donnée, déclencher une opération, énumérer les comptes |
| Lecteur | Consulter les tableaux de bord de sa portée | Lire des journaux, des fichiers, des sorties de tâches hors portée |
| Éditeur | Opérations `routine` de sa portée | Opérations `change`, `destructive`, `critical` |
| Administrateur | Opérations jusqu'à `destructive` de sa portée | Obtenir les droits Super Admin, lire les secrets du panel, désactiver l'audit |
| Super Admin | Tout dans son organisation | Exécuter du shell libre (sauf extension explicitement activée), effacer l'audit |
| Processus web compromis (RCE PHP) | Ce que l'utilisateur `hostkeep` peut faire | Obtenir root sur un serveur géré, lire les secrets en clair, contourner la politique locale de l'agent |
| Central compromis | Demander des opérations du catalogue | Exécuter autre chose que le catalogue ; outrepasser la politique locale d'un agent |
| Agent compromis (sur un serveur) | Mentir sur ses propres données | Atteindre les autres serveurs, lire les secrets d'autres serveurs |
| Chaîne d'approvisionnement | Publier un commit | Faire installer une release non signée |
| Voisin réseau / conteneur local | Envoyer des requêtes HTTP locales | Usurper l'IP cliente, s'authentifier comme agent |

---

## 2. Correctifs immédiats (P0, version 2.10)

| ID | Correctif | Critère de validation |
|---|---|---|
| S1 | Destination « Autre » rclone : **liste blanche de clés** par backend (`type`, `provider`, `endpoint`, `region`, `access_key_id`, `secret_access_key`, `host`, `user`, `port`, `key_pem`, `pass`, `token`, `client_id`, `client_secret`, `root_folder_id`, `scope`, `drive_id`, `url`, `vendor`, `account`, `key`…), refus explicite de `type = local|alias|union|combine|chunker` et de toute clé `ssh`, `*command*`, `*_exec`. rclone lancé avec `--config` en lecture seule. **Étape suivante :** rclone sous un utilisateur dédié non root. | Test unitaire : chaque clé interdite est refusée à l'enregistrement **et** ignorée à l'écriture de `rclone.conf` |
| S2 | Explorateur : `/var/www/hostkeep` et `/etc/hostkeep` interdits ; motifs refusés (`.env*`, `*.key`, `*.pem`, `*secret*`, `wp-config.php`, `*.sql*`, `id_*`) sauf permission dédiée `files.read_sensitive` (Super Admin). Téléchargement de la base `hostkeep` réservé au Super Admin. | Tests : un Administrateur reçoit 403 sur `.env` de Hostkeep et sur `hostkeep.sql.gz` |
| S3 | Releases signées (minisign) sur tags ; `self-update` vérifie la signature avec la clé publique embarquée ; refus d'une branche non taguée sauf mode « développeur » explicite. | Une release au contenu modifié est refusée |
| S4 | Jobs : auteur enregistré, lecture réservée à l'auteur et aux rôles ayant la permission de l'action ; identifiants UUID v4. | Un Lecteur reçoit 403 sur la sortie d'une tâche Administrateur |
| S5 | Mode proxy : `set_real_ip_from` limité à l'adresse du proxy détecté (option `--trusted-proxy`). | Un en-tête `X-Forwarded-For` venant d'une autre IP est ignoré |
| B1 | Le résultat d'un job est écrit par le helper (fichier `rc`) **et** réconcilié par le collecteur en fin de cycle. | Aucune ligne d'audit avec `rc NULL` après 10 min |
| B2 | Clé d'alerte stable (`type:ressource`) indépendante du texte. | Une alerte RAM qui varie de 7 % à 6 % n'envoie aucune nouvelle notification |
| — | `SECURITY.md` : contact, délai de réponse (72 h), politique de divulgation coordonnée (90 j), versions supportées. | Fichier publié |

---

## 3. Exigences permanentes

Chaque exigence est vérifiable ; « Contrôle » indique comment.

### 3.1 Privilèges et exécution
| Exigence | Contrôle |
|---|---|
| Aucun processus exposé au réseau ne tourne en root (web, worker, agent, passerelle) | Test d'installation : `ps -o user` sur les services |
| Aucune exécution de commande construite à partir d'une entrée utilisateur ; arguments toujours passés en tableau, jamais par un shell | Règle Semgrep : interdit `shell_exec`, `exec`, `system`, `passthru`, backticks hors liste blanche de fichiers ; `proc_open` uniquement avec tableau |
| Toute modification système passe par une **opération du catalogue** | Revue : aucune nouvelle route ne peut appeler l'exécuteur hors du moteur d'opérations |
| Revalidation complète côté exécuteur (le central n'est pas cru) | Tests unitaires de chaque validateur côté agent/helper |
| Tout réglage lu par un processus privilégié est traité comme non fiable et validé **au moment de l'usage** | Revue + tests (leçon de S1) |
| Politique locale de l'agent non modifiable à distance par défaut | Test : une opération interdite localement est refusée même signée |
| Durcissement systemd de chaque service (`NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, `RestrictAddressFamilies`, `SystemCallFilter=@system-service`) | `systemd-analyze security` ≤ 3.0 (« OK ») pour web, worker, agent |

### 3.2 Entrées et sorties
| Exigence | Contrôle |
|---|---|
| Validation stricte par schéma (types, motifs ancrés, bornes) à l'entrée de chaque route et opération | Tests de contrat générés depuis le catalogue |
| Requêtes SQL toujours préparées ; noms de tables/colonnes jamais issus d'une entrée | Semgrep + PHPStan |
| Aucune donnée insérée par `innerHTML` ; CSP sans `unsafe-inline` ni `unsafe-eval` | Semgrep JS + test d'en-têtes |
| Chemins : `realpath` + appartenance à une racine autorisée + liste de refus | Tests avec `..`, liens symboliques, octets nuls |
| Requêtes sortantes (webhooks, sondes) : HTTP/HTTPS seulement, pas de redirection, refus optionnel des IP privées/metadata (169.254.169.254) | Test SSRF |
| Téléversements : taille max, nom normalisé, jamais exécutables dans une racine web sans action explicite | Tests |

### 3.3 Authentification et sessions
| Exigence | Contrôle |
|---|---|
| MFA obligatoire pour tout rôle pouvant exécuter des opérations `change` et au-delà (défaut actuel conservé) | Test de politique |
| Passkeys (WebAuthn) comme facteur principal ou second facteur | Phase 1 |
| Ré-authentification récente (≤ 5 min) pour les opérations `critical` et les changements de sécurité | Test |
| Limitation des tentatives par IP **et** par compte, y compris sur le second facteur | Tests |
| Sessions : rotation d'identifiant à la connexion, révocation, expiration d'inactivité et absolue (12 h) | Tests |
| Jetons d'API : hachés, préfixés (`hk_`), portée minimale, expiration ≤ 1 an, restriction IP optionnelle, dernière utilisation visible | Tests |
| CSRF sur toute requête à effet authentifiée par cookie ; jamais de cookie accepté sur les routes à jeton | Tests |

### 3.4 Secrets
| Exigence | Contrôle |
|---|---|
| Chiffrement par **enveloppe** : clé de données par secret (AES-256-GCM), enveloppée par une clé maître versionnée | Phase 1 |
| Clé maître hors base (fichier root:hostkeep 0640 aujourd'hui → fichier lisible du seul service central + option KMS/TPM plus tard) | Revue |
| Rotation de la clé maître sans interruption (rechiffrement progressif) | Commande `hostkeep secrets rotate` testée |
| Secrets jamais renvoyés au navigateur après saisie ; jamais dans les journaux, sorties de jobs, archives de migration | Tests + filtre de journalisation |
| Secrets transmis à un agent chiffrés pour sa clé publique, uniquement pour l'opération concernée | Revue protocole |

### 3.5 Isolation multi-organisations
| Exigence | Contrôle |
|---|---|
| Toute lecture/écriture passe par un dépôt qui impose `org_id` | Règle PHPStan personnalisée : pas de requête sur une table « organisationnelle » hors dépôt |
| Identifiants non séquentiels exposés (UUID) | Revue |
| Tests automatiques d'accès croisé : pour chaque route, un utilisateur de l'org A demande une ressource de l'org B → 404 | Suite générée depuis la liste des routes |

### 3.6 Journalisation et audit
| Exigence | Contrôle |
|---|---|
| Toute opération, connexion, changement de compte, de rôle, de réglage, de secret, d'extension est journalisée (qui, quoi, quand, d'où, résultat) | Test : chaque route POST produit une ligne d'audit |
| Journal **en ajout seul** et **chaîné** (hash de la ligne précédente) ; vérification `hostkeep audit verify` | Test d'altération détectée |
| Export vers journald/syslog et fichier JSON (rotation) pour envoi vers un SIEM | Option |
| Rétention ≥ 1 an par défaut, non réductible par un Administrateur | Test |

---

## 4. Classification des opérations

Chaque opération du catalogue porte un niveau de risque qui fixe ses garde-fous. **Les opérations dangereuses sont identifiées visuellement** (couleur, icône, libellé) dans l'interface et marquées dans l'API.

| Niveau | Exemples | Rôle minimal par défaut | Confirmation | Aperçu | Retour arrière |
|---|---|---|---|---|---|
| `read` | Lire l'état, les métriques | Lecteur | — | — | — |
| `routine` | Lancer un contrôle, sauvegarder, redémarrer un service non critique, débannir une IP | Éditeur | Simple | Si utile | — |
| `change` | Activer un site, modifier un vhost, ajouter une règle de pare-feu, créer une base, mettre à jour des paquets | Administrateur | Simple + résumé des effets | **Obligatoire** (diff, fichiers, services touchés) | Automatique si l'opération est réversible ; *confirm-or-revert* pour réseau et SSH |
| `destructive` | Supprimer une base, un site, un conteneur, un volume ; restaurer par-dessus ; vider la corbeille | Administrateur | **Saisie du nom** de la ressource | Obligatoire | Copie de sécurité préalable automatique quand c'est possible |
| `critical` | Redémarrer le serveur, modifier SSH, désactiver le pare-feu, mettre à jour Hostkeep, rotation de clé, opérations groupées sur > 10 serveurs | Super Admin | Saisie + **ré-authentification** (passkey/TOTP) ; option **quatre yeux** | Obligatoire | *Confirm-or-revert* ou plan de retour documenté |

**Confirm-or-revert** (réseau, pare-feu, SSH) : l'agent applique la modification, programme son annulation dans 60 s, puis attend une confirmation **qui doit arriver par une nouvelle connexion** (preuve que l'accès fonctionne toujours). Sans confirmation, retour automatique.

**Protections absolues** (refusées par l'exécuteur quel que soit le rôle) : arrêter/désactiver `sshd` ou l'agent, supprimer le dernier utilisateur sudo, règle de pare-feu bloquant le port SSH actif sans règle d'autorisation équivalente, `rm` hors des racines gérées, modification de la politique locale de l'agent à distance.

---

## 5. Agent et communications

| Exigence | Détail |
|---|---|
| Aucun port entrant | L'agent initie toutes les connexions |
| mTLS | Certificat client par agent (90 j, renouvellement auto), CA interne épinglée |
| Requêtes signées | Signature Ed25519 du central sur (`job_id`, opération, hachage des paramètres, `nonce`, `expires_at`) ; nonce mémorisé pour anti-rejeu |
| Révocation | Retirer un serveur invalide son certificat immédiatement (liste de révocation consultée à chaque connexion) |
| Séparation des privilèges | `hostkeep-agent` (non root) ↔ `hostkeep-exec` (root, socket unix, `SO_PEERCRED`) |
| Mises à jour de l'agent | Binaires signés, vérifiés par l'agent avant remplacement ; retour automatique si pas de reconnexion |
| Enrôlement | Jeton à usage unique, 1 h, lié à l'organisation ; script d'installation signé |

---

## 6. Chaîne d'approvisionnement

| Exigence | Échéance |
|---|---|
| Releases signées (archives + binaires agent), sommes SHA-256 publiées | P0 |
| Builds reproductibles de l'agent Go (`-trimpath`, version figée de Go) | Phase 2 |
| Protection de branche `main` (revue obligatoire, CI verte, commits signés) | P0 |
| SBOM (CycloneDX) publié avec chaque release | Phase 1 |
| Dépendances : Dependabot/Renovate, `composer audit`, `govulncheck` en CI | Dès l'introduction de dépendances |
| Actions GitHub épinglées par SHA, permissions minimales | Phase 1 |
| Catalogue d'applications et extensions signés | À leur création |

---

## 7. Tests de sécurité automatisés

| Outil / suite | Cible | Bloquant en CI | Phase |
|---|---|:---:|---|
| **PHPStan** niveau 6 → 8 (+ règles maison : SQL, org_id, exec) | PHP | Oui | 1 |
| **Semgrep** (règles PHP/JS/Bash communautaires + maison) | Tout | Oui (sévérité ≥ ERROR) | 1 |
| **shellcheck** (existant) | Bash | Oui | — |
| **bats** : chaque verbe du helper avec entrées valides, invalides, injections (`;`, `$( )`, `..`, octets nuls, Unicode) | Helper / exécuteur | Oui | 0–1 |
| **PHPUnit** : validateurs, RBAC (matrice rôle × permission × portée générée), TOTP, chiffrement, parsing des configs web | PHP | Oui | 1 |
| **Tests d'accès croisé** org A / org B générés depuis les routes | API | Oui | dès les organisations |
| **Fuzzing** des validateurs de paramètres et du parseur de protocole agent (`go test -fuzz`) | Agent | Non (nocturne) | 2 |
| **OWASP ZAP baseline** contre une instance de test (en-têtes, cookies, XSS réfléchi) | Web | Oui (alertes High) | 1 |
| **Test d'installation** en conteneur systemd / VM : services non root, permissions de fichiers, `systemd-analyze security` | Installation | Oui | 1 |
| **gitleaks** | Dépôt | Oui | 0 |
| **CodeQL** (JS, Go) | Front, agent | Oui (High) | 1–2 |
| **Audit externe** par un tiers indépendant | Tout | — | Avant 4.0 |

**Objectifs mesurables :** 0 constat High ouvert à chaque release ; correctif d'une faille critique publié en ≤ 7 jours ; 100 % des opérations du catalogue couvertes par des tests de validation (valide/invalide) ; 100 % des routes couvertes par la matrice RBAC.

---

## 8. Calendrier

| Phase | Sécurité livrée |
|---|---|
| **0 — 2.10** | S1–S5, B1, B2, `SECURITY.md`, gitleaks, bats sur le helper, matrice PHP 8.1–8.4, protection de branche |
| **1 — 3.0** | Catalogue d'opérations avec niveaux de risque, aperçu, confirmations ; audit chaîné (S6) ; dumps via helper (S7) ; secrets par enveloppe ; passkeys ; ré-authentification ; PHPStan, Semgrep, PHPUnit, ZAP ; SBOM |
| **2 — 3.1/3.2** | Agent mTLS + requêtes signées + politique locale ; PHP sans sudo (`NoNewPrivileges`) ; fuzzing ; builds reproductibles |
| **3+** | Organisations + tests d'accès croisé ; jetons d'API à portée ; quatre yeux ; extensions signées ; audit externe |
