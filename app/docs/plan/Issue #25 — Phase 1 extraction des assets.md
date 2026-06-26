# Issue #25 — Phase 1 : Extraction des assets (CSS/JS)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 1 (découplage progressif). Les pages `app/public/*.php` mêlent logique PHP,
HTML, CSS et JS inline. Objectif : sortir le CSS et le JS vers des assets
statiques versionnés, sans build tooling (PHP vanilla). Le filet de tests de la
Phase 0 (PR #27, mergée) couvre déjà le cœur métier.

> **Incrément 1/n — `index.php`** : on traite d'abord le plus gros fichier
> (1522 lignes : ~350 de CSS + ~750 de JS inline) et on pose le **pipeline
> d'assets réutilisable** (helper de cache-busting). `tariffs.php` et `login.php`
> + la factorisation cross-pages suivront en incréments séparés.

## Fichiers impactés

- [app/src/Support/Assets.php](app/src/Support/Assets.php) — **nouveau** helper
  `Assets::url()` : suffixe `?v=<filemtime>` (cache-busting automatique, sans build).
- [app/public/assets/css/dashboard.css](app/public/assets/css/dashboard.css) —
  **nouveau**, CSS extrait verbatim du `<style>` d'index.php.
- [app/public/assets/js/dashboard.js](app/public/assets/js/dashboard.js) —
  **nouveau**, JS extrait verbatim du gros `<script>` d'index.php.
- [app/public/index.php](app/public/index.php) — le `<style>` devient un `<link>`
  et le gros `<script>` un `<script src>` (via `Assets::url()`). Le **pont d'init**
  PHP→JS (`window.__INIT_*`, données dynamiques) **reste inline** : c'est la seule
  injection PHP du bloc JS.

## Étapes

- [x] Helper `App\Support\Assets` (cache-busting `filemtime`)
- [x] Extraire le CSS (`<style>` → `assets/css/dashboard.css`)
- [x] Extraire le JS (`<script>` → `assets/js/dashboard.js`)
- [x] Remplacer les blocs inline par `<link>` / `<script src>` versionnés
- [x] Conserver le pont d'init PHP→JS inline (données dynamiques)
- [x] `tariffs.php` → `assets/css/tariffs.css` + `assets/js/tariffs.js`
- [x] `login.php` → `assets/css/login.css` (style autonome, hors tokens)
- [x] Factorisation des tokens communs (reset + `:root`) → `assets/css/tokens.css`
      partagé par `index.php` et `tariffs.php`

## Vérification

1. `php -l` sur `index.php` et `Assets.php` — OK.
2. `Assets::url()` contre les vrais fichiers : renvoie `…?v=<mtime>`.
3. **Smoke render** (serveur PHP intégré, config bidon) : `index.php` → HTTP 200,
   URL d'assets versionnées rendues, `dashboard.css`/`dashboard.js` servis en 200,
   dégradation gracieuse (DB indisponible) sans erreur fatale.
4. PHPStan niveau 5 (`app/public` + `app/src` analysés) — 0 erreur.
5. PHPUnit (Phase 0) — 22 tests verts (inchangés).

## Hors périmètre (suite Phase 1)

- Extraction CSS/JS de `tariffs.php` et `login.php`.
- Factorisation du CSS/JS **commun** entre pages (tokens, helpers `fetch`).
- Self-hosting éventuel de chart.js (CSP) — conservé en CDN pour l'instant.
