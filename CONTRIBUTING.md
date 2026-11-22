# Contribuer à Hostkeep

Merci de ton intérêt ! Avant de commencer, lis [`docs/strategy/MASTER_PLAN.md`](docs/strategy/MASTER_PLAN.md) : il dit où va le projet et quelles sont les priorités.

## Les règles qui ne se négocient pas

1. **Jamais de shell côté web.** Le PHP ne construit aucune commande ; il appelle le helper root avec un tableau d'arguments (`run_helper()`).
2. **Toute action root passe par la liste blanche** de `system/hostkeep-action` (`validate()` puis `run()`), qui revalide chaque argument contre l'état réel du serveur.
3. **Tout ce qu'un processus root lit est une entrée non fiable**, y compris les réglages enregistrés par le panel.
4. **Les droits sont vérifiés côté serveur** à chaque route (`require_perm()`) ; l'interface ne fait que masquer.
5. **Aucune donnée dans `innerHTML`** : le front construit le DOM avec `el()` et `textContent`.
6. **Pas de dépendance** sans discussion préalable (voir la décision D-05 dans `docs/strategy/TECHNICAL_DECISIONS.md`).

## Ajouter une action root

Aujourd'hui, une action se déclare à quatre endroits (le catalogue d'opérations de la Phase 1 les réunira) :

| Où | Quoi |
|---|---|
| `app/src/actions.php` · `ACTIONS` | expression régulière de chaque argument |
| `app/src/rbac.php` · `ACTION_PERMS` | permission requise |
| `app/public/assets/app.js` · `VERB_PERMS` | même permission (affichage) |
| `system/hostkeep-action` · `validate()` et `run()` | revalidation root et exécution |

Ajoute un test dans `tests/helper/` pour les arguments refusés (injection, `..`, valeurs inconnues).

## Travailler en local

Il n'y a rien à compiler. Pour voir l'interface sans serveur ni PHP :

```bash
python3 tools/mock.py app/public 8766                # compte de démo Super Admin
```

```bash
python3 tools/mock.py app/public 8767 role=admin     # l'interface vue par un Administrateur
```

puis ouvre `http://localhost:8766`. Les données sont factices et les actions simulées.

## Tests

```bash
for t in tests/php/*_test.php; do php "$t" || break; done
```

```bash
for t in tests/helper/*_test.sh; do bash "$t" || break; done
```

Le CI lance aussi la syntaxe PHP de 8.1 à 8.4, shellcheck, la syntaxe JS, le schéma SQL et gitleaks. Sans PHP installé :

```bash
docker run --rm -v "$PWD:/src" -w /src php:8.1-cli sh -c 'for t in tests/php/*_test.php; do php "$t" || exit 1; done'
```

## Branches, commits, PR

- Active les hooks du dépôt une fois après le clonage : `git config core.hooksPath tools/git-hooks`. Le hook `commit-msg` refuse les lignes d'attribution générées par des outils (co-auteur IA, pied de page « Generated with … ») ; le CI fait la même vérification sur chaque commit et sur les fichiers.

- Branches depuis `develop` : `feature/…`, `fix/…` ; `main` ne reçoit que les versions publiées.
- Messages de commit en français, style *Conventional Commits* (`fix(security): …`, `feat: …`, `docs: …`), avec l'identifiant du constat ou de la fonctionnalité quand il existe (`S2`, `NET-02`…).
- Une PR = un sujet ; mets à jour `CHANGELOG.md` (section *Non publié*) et le README si le comportement change.
- Les versions sont des tags signés (voir [`docs/RELEASING.md`](docs/RELEASING.md)).

## Signaler une faille

Pas de ticket public : voir [`SECURITY.md`](SECURITY.md).
