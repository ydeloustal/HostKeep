# Politique de sécurité

Hostkeep administre des serveurs en root : une faille peut coûter très cher à ceux qui l'utilisent. Merci de prendre le temps de la signaler correctement.

## Signaler une faille

**Ne publie pas de ticket public.** Utilise le signalement privé de GitHub :
onglet **Security** du dépôt › **Report a vulnerability**.

Indique si possible :
- la version (`sudo hostkeep version`) et la distribution ;
- le rôle nécessaire (aucun compte, Lecteur, Éditeur, Administrateur, Super Admin) ;
- les étapes pour reproduire, et ce qu'un attaquant obtient.

## Ce qui se passe ensuite

| Délai | Engagement |
|---|---|
| 72 h | Accusé de réception |
| 7 jours | Première évaluation (gravité, versions touchées) |
| 7 jours après confirmation | Correctif publié pour une faille critique (exécution de code, prise de contrôle, accès aux secrets) |
| 90 jours au plus | Publication coordonnée de l'avis, avec ton nom si tu le souhaites |

## Versions maintenues

Seule la **dernière version mineure** reçoit les correctifs de sécurité. Mets à jour depuis *Administration › Version* ou avec `sudo hostkeep update`.

## Périmètre

Dans le périmètre : l'application (`app/`), le helper root (`system/hostkeep-action`), la commande `hostkeep`, l'installateur, les mises à jour, les configurations générées.

Particulièrement utiles :
- exécution de commandes en root hors de la liste blanche du helper ;
- passage d'un rôle à un rôle supérieur, ou accès aux secrets du panel sans être Super Admin ;
- contournement de la double authentification, des sessions ou de la liste d'IP autorisées ;
- installation d'une mise à jour non signée alors qu'une clé est configurée.

Hors périmètre : les failles des logiciels tiers eux-mêmes (PHP, MariaDB, Nginx…), les attaques qui supposent déjà un accès root au serveur, l'absence d'en-têtes sur des pages qui n'en ont pas besoin.

## Ce que Hostkeep fait déjà

Le modèle de sécurité est décrit dans le [README](README.md#-sécurité) et la feuille de route dans [`docs/strategy/SECURITY_ROADMAP.md`](docs/strategy/SECURITY_ROADMAP.md).
