# Issue #25 — Phase 2 : Couche templates / vues

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 2 (découplage progressif). Après l'extraction des assets (Phase 1), on
sépare la **présentation** (HTML) de la **logique** : un moteur de rendu minimal
et des templates sous `app/templates/`, avec **échappement centralisé**. Les
`public/*.php` deviennent : bootstrap → préparation des données → rendu de la vue.

> **Incrément 1/n — fondation + `login.php`** : on pose le moteur de vues et on
> convertit la page la plus simple comme preuve du pattern. `index.php` et
> `tariffs.php` (plus volumineux) suivront en incréments séparés.

## Fichiers impactés

- [app/src/View/View.php](app/src/View/View.php) — **nouveau** moteur :
  `render(string $template, array $data): string` (template PHP inclus dans le
  contexte de l'instance via closure liée, output buffering) + `e()` échappement
  HTML centralisé (`htmlspecialchars`, ENT_QUOTES|ENT_SUBSTITUTE, UTF-8).
- [app/templates/login.php](app/templates/login.php) — **nouveau** template
  (HTML extrait verbatim de l'ancien `login.php`, `htmlspecialchars(...)` →
  `$this->e(...)`).
- [app/public/login.php](app/public/login.php) — la logique (config, sécurité,
  i18n, auth POST) **reste** ; le HTML inline est remplacé par
  `echo (new View(...))->render('login', [...])`.

## Étapes

- [x] Moteur `App\View\View` (rendu + échappement centralisé `e()`)
- [x] Template `templates/login.php`
- [x] `public/login.php` : data → `View::render`
- [x] Convertir `index.php` → `templates/dashboard.php` (corps + helpers `fmt`/`fmtCost`)
- [ ] (suite) Convertir `tariffs.php` (formulaire + lignes tarifaires)
- [ ] (suite) Partials réutilisables (header, footer, navigation)

## Vérification

1. `php -l` sur `View.php`, `login.php`, `templates/login.php` — OK.
2. Rendu du template via le moteur (CLI) : titre correct, asset versionné,
   et **payload XSS dans `error` correctement échappé** (`<script>` → `&lt;…`).
3. PHPStan niveau 5 (`app/src` + `app/public`) — 0 erreur. (`app/templates/`
   hors analyse : variables extraites, non statiquement analysables.)
4. Smoke render (serveur PHP intégré) : `login.php` → HTTP 302 (logique de
   redirection préservée), `index.php` → 200 (inchangé), aucune erreur fatale.

## Décisions

- **PHP vanilla, pas de moteur tiers** (Twig…) : templates PHP + `$this->e()`
  explicite. Échappement **manuel mais centralisé** (un seul point : `View::e()`).
- `app/templates/` hors `app/src/` (ce ne sont pas des classes) et hors analyse
  PHPStan (extraction de variables).
