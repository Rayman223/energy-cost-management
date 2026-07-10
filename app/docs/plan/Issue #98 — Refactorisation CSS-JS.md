# Issue #98 — Refactorisation CSS/JS

## Contexte

Issue #98 « Refactorisation CSS/JS » : isoler **tout** le CSS et le JS des pages
dans `app/public/assets/css/` et `app/public/assets/js/`, éliminer le CSS/JS
inline restant, puis durcir la CSP. Lien : https://github.com/Rayman223/Manage-energy-costs/issues/98

L'essentiel des assets est déjà externalisé ; le chantier vise les résidus inline
(blocs `<style>`, attributs `style=`, scripts inline anti-FOUC et données) et le
durcissement final de la Content-Security-Policy.

## Fichiers impactés

- [app/templates/partials/_head.php](app/templates/partials/_head.php) — **créé**, `<head>` mutualisé
- [app/public/assets/js/theme-init.js](app/public/assets/js/theme-init.js) — **créé**, anti-FOUC externalisé
- [app/public/assets/css/error.css](app/public/assets/css/error.css), `legal.css`, `backoffice.css`, `admin.css`, `account.css` — **créés**
- Les 7 templates ([app/templates/](app/templates/)) — `<head>` via partial, suppression du CSS/JS inline
- [app/templates/partials/_import_report.php](app/templates/partials/_import_report.php) — classes au lieu de `style=`
- [app/public/assets/css/tokens.css](app/public/assets/css/tokens.css), `dashboard.css`, `tariffs.css`, `login.css` — nouvelles classes
- [app/public/assets/js/dashboard.js](app/public/assets/js/dashboard.js) — styles runtime → classes, data block JSON, toggles `is-hidden`
- [app/public/assets/js/tariffs.js](app/public/assets/js/tariffs.js) — toggle `is-hidden`
- [app/src/Http/SecurityHeaders.php](app/src/Http/SecurityHeaders.php) + [tests/Unit/Http/SecurityHeadersTest.php](tests/Unit/Http/SecurityHeadersTest.php) — CSP durcie

## Étapes (une phase = un commit non-cassant)

- [ ] **Phase 1** — theme-init.js + partial `_head.php` + branchement dans les 7 templates
- [ ] **Phase 2** — blocs `<style>` (error/legal/admin/account) → fichiers CSS
- [ ] **Phase 3** — attributs `style=` des templates → classes (+ toggles JS `is-hidden`)
- [ ] **Phase 4** — 33 styles runtime de dashboard.js → classes
- [ ] **Phase 5** — data block JSON dashboard + prélude bootstrap dashboard.js
- [ ] **Phase 6** — durcissement CSP (suppression nonce + `'unsafe-inline'`)

## Vérification

- `php -l` sur chaque `.php` modifié ; `node --check` sur chaque `.js` modifié.
- PHPStan niveau 6 (`phpstan.dist.neon`), rien ajouté à la baseline.
- PHPUnit (unit + integration).
- Contrôle visuel navigateur (console ouverte, 0 erreur CSP) : login, dashboard
  (nav mois/année élec/gaz/eau, boutons 30/60/90, tarif dynamique), tariffs
  (détail grille, ajout/suppression champ, « sauver comme template »), account/admin
  (import dry-run), pages légales, 404. Hard-refresh en thème sombre → pas de FOUC.
