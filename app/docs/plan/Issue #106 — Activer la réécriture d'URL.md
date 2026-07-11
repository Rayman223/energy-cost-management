# Issue #106 — Activer la réécriture d'URL

## Contexte
L'app exposait des URLs avec extension `.php` (`/account.php`, `api.php?action=…`),
chaque page étant un front controller direct dans `app/public/`. L'issue demande des
**URLs propres sans extension** et de modifier **tous les liens** en conséquence.
Lien GH : https://github.com/Rayman223/Manage-energy-costs/issues/106

Décisions : (1) URLs sans extension, mapping 1:1 ; (2) **front controller PHP unique**
(`public/index.php` route tout, serveur en `try_files → index.php`) ; (3) helper
`Url::to()` / `View::url()` centralisé pour générer les liens.

## Fichiers impactés
- [app/public/index.php](../../public/index.php) — **front controller** : URL propre →
  `require app/routes/<page>.php` ; 404 inconnu ; **308** legacy `/xxx.php → /xxx`
  (préserve POST pour l'API) ; passthrough assets sous `php -S`.
- [app/src/Support/Url.php](../../src/Support/Url.php) — `Url::to()` (préfixe `appRootPath`).
- [app/src/View/View.php](../../src/View/View.php#L120) — méthode `url()`.
- `app/routes/*.php` + `app/routes/auth/*.php` — pages déplacées depuis `app/public/`
  (dashboard extrait de l'ancien `index.php`).
- [app/src/Security/WebAccessGuard.php](../../src/Security/WebAccessGuard.php#L141) —
  `isLoginPageRequest()` sur le **chemin de requête** ; `buildLoginPath()` → `/login`.
- [app/src/Security/AuthGuard.php](../../src/Security/AuthGuard.php#L100) — cible `/auth/login`.
- [app/routes/auth/login.php](../../routes/auth/login.php#L57) — `redirect_uri` OIDC
  dérivé de `appRootPath() . '/auth/login'` (SCRIPT_NAME = `/index.php` désormais).
- Templates ([app/templates/](../../templates/)) — liens `.php` → `$this->url(...)`.
- JS ([dashboard.js](../../public/assets/js/dashboard.js), [meter-readings.js](../../public/assets/js/meter-readings.js)) — `api.php?action=` → `api?action=`.
- [phpstan.dist.neon](../../../phpstan.dist.neon) — ajout de `app/routes` aux chemins analysés.
- Docs : installation.md (Nginx + `php -S`), architecture.md, api-contract.md, suivi.md,
  config.example.php, agent_push.php.

## Étapes
- [x] Helper `Url::to()` + `View::url()`.
- [x] Déplacer les pages vers `app/routes/`, extraire le dashboard.
- [x] Front controller `public/index.php` (map + 308 legacy + passthrough `php -S`).
- [x] Adapter WebAccessGuard / AuthGuard / callback OIDC.
- [x] Liens templates + `fetch` API JS.
- [x] Docs + `phpstan.dist.neon`.

## Vérification
- `php -l` OK sur tous les fichiers ; **PHPStan niveau 6 : 0 erreur** ;
  **PHPUnit : 239 tests OK** (39 skippés = BDD).
- Bout en bout via `php -S localhost:8000 -t app/public app/public/index.php` :
  `/` → 302 `/login` propre · `/login` 200 (pas de boucle) · `/account.php` → **308**
  `/account` · `/index.php` → 308 `/` · `/nonexistent` 404 · `/api` atteint ·
  assets servis · `/tariffs`, `/auth/login` OK.

## Hors périmètre (suites éventuelles)
- Lien outil admin `../tools/migrate_db.php` ([tariffs.php:409](../../templates/tariffs.php#L409))
  et back-links des outils `app/tools/*` (hors routage clean-URL).
