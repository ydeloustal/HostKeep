# Publier une version signée

Le bouton **Mettre à jour** du panel (et `sudo hostkeep update`) exécute le code du dépôt **en root** sur chaque serveur. Dès qu'une clé est listée dans [`system/allowed_signers`](../system/allowed_signers), ils n'installent plus que des **tags de version signés** par une clé de cette liste : un commit poussé par quelqu'un d'autre (compte GitHub volé, dépôt compromis) n'est jamais installé.

Tant que la liste est vide, les mises à jour suivent la branche comme avant, et le panel affiche « non vérifiées ».

## 1. Créer la clé de signature (une fois)

Sur **ton poste** (jamais sur un serveur géré) :

```bash
ssh-keygen -t ed25519 -C "hostkeep-release" -f ~/.ssh/hostkeep_release
```

Choisis une phrase de passe. Garde une copie de la clé privée hors de ton poste (gestionnaire de mots de passe, clé USB chiffrée) : sans elle, plus aucune version ne pourra être publiée pour les serveurs déjà en mode signé.

## 2. Déclarer la clé publique

Ajoute une ligne à `system/allowed_signers` :

```bash
echo "release@hostkeep namespaces=\"git\" $(cat ~/.ssh/hostkeep_release.pub)" >> system/allowed_signers
```

Puis commite et publie cette modification **dans une version signée** (étape 3). Les serveurs passent en mode signé quand ils installent cette version : à partir de là, la liste qu'ils utilisent est celle de la version installée, jamais celle d'un nouveau commit.

## 3. Publier une version

```bash
git config gpg.format ssh
git config user.signingkey ~/.ssh/hostkeep_release.pub
# sur main, après la fusion de develop et la mise à jour de VERSION et CHANGELOG.md :
git tag -s v2.10.0 -m "Hostkeep 2.10.0"
git verify-tag v2.10.0 -c gpg.ssh.allowedSignersFile=system/allowed_signers   # contrôle
git push origin main v2.10.0
```

Les serveurs installent le **plus récent tag `vX.Y.Z` signé** qui descend de leur version actuelle. Un tag non signé, ou signé par une clé absente de la liste, est ignoré (et signalé dans la sortie de la mise à jour).

## 4. Changer de clé

Ajoute la nouvelle clé à `system/allowed_signers` **à côté** de l'ancienne, publie une version signée avec l'**ancienne** clé, puis retire l'ancienne dans une version suivante signée avec la nouvelle.

## Limites

- La réinstallation complète par la commande en une ligne (`curl … | sudo bash`) clone toujours la branche `main` : elle repose sur la confiance dans GitHub au moment de l'installation.
- Un serveur installé depuis une copie modifiée à la main reste en mode « non vérifié » tant qu'il n'a pas installé une version contenant une clé.
