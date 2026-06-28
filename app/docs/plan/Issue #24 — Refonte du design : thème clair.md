# Issue #24 — Refonte du design : thème clair + lisibilité (toggle clair/sombre)

## Contexte

Le design est 100 % sombre et certains textes sont trop petits → lisibilité et confort
dégradés ([issue #24](https://github.com/Rayman223/Manage-energy-costs/issues/24)).

Objectif : **thème clair, lisible et cohérent** par défaut, sombre conservé. Décisions :
- **Toggle clair/sombre + respect système** : clair par défaut, bascule mémorisée
  (localStorage), suivi de `prefers-color-scheme` tant qu'aucun choix manuel.
- **Palette repensée** : nouveaux accents plus doux/modernes pour le clair.

Architecture déjà tokenisée : [tokens.css](../../public/assets/css/tokens.css) est la
source unique, consommée par dashboard.css / tariffs.css. login.css était autonome.

## Mécanique de thème

Attribut `data-theme="light|dark"` sur `<html>`, résolu **avant le 1er rendu** par un
snippet inline anti-FOUC : `localStorage['theme']` → sinon `matchMedia(prefers-color-scheme: dark)`.
CSS : deux jeux de variables seulement (`:root` clair par défaut, `:root[data-theme="dark"]`).

## Fichiers impactés

- [app/public/assets/css/tokens.css](../../public/assets/css/tokens.css) — palettes
  claire/sombre, variables `--text-strong`, `--dotgrid`, `--on-amber`, style `.theme-toggle`,
  transition body.
- [app/public/assets/css/dashboard.css](../../public/assets/css/dashboard.css) — hex en
  dur → variables ; relèvement des tailles de police.
- [app/public/assets/css/tariffs.css](../../public/assets/css/tariffs.css) — idem.
- [app/public/assets/css/login.css](../../public/assets/css/login.css) — variables tokens
  + textes agrandis.
- [app/public/assets/js/theme.js](../../public/assets/js/theme.js) — **nouveau** : toggle,
  persistance, suivi système.
- [app/templates/dashboard.php](../../templates/dashboard.php),
  [app/templates/tariffs.php](../../templates/tariffs.php) — snippet anti-FOUC, bouton
  toggle, chargement theme.js.
- [app/templates/login.php](../../templates/login.php) — anti-FOUC + chargement tokens.css.

## Étapes

- [ ] tokens.css : palettes + variables + style toggle + transition
- [ ] dashboard.css & tariffs.css : variables + tailles
- [ ] login.css : variables + tailles
- [ ] theme.js
- [ ] templates : anti-FOUC + toggle + chargements

## Vérification

`php -l` sur les templates. App servie (`php -S localhost:8000 -t app/public`) : clair par
défaut, bascule sans flash/persistée, login hérite du thème, contraste WCAG AA, responsive
1280/1024/768/480.
