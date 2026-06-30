# Issue #51 — P0 Fondations & sécurité publique

## Contexte
Première phase de l'épopée #47 (passage en plateforme communautaire européenne). P0 pose les fondations
techniques et sécuritaires nécessaires **avant** toute exposition publique et avant la restructuration multi-tenant
(P2). Tout est **additif et non-cassant**. Lien : https://github.com/Rayman223/Manage-energy-costs/issues/51

## Fichiers impactés
- [app/src/Infrastructure/MigrationRunner.php](app/src/Infrastructure/MigrationRunner.php) — runner de migrations versionnées (nouveau)
- [app/scripts/migrate.php](app/scripts/migrate.php) — CLI d'application des migrations (nouveau)
- [app/sql/schema.sql](app/sql/schema.sql) — table `schema_migrations` + baseline des migrations existantes
- [app/scripts/deploy_unraid.sh](app/scripts/deploy_unraid.sh) — étape 4 : `migrate.php`
- [app/bootstrap.php](app/bootstrap.php) — câblage de `vendor/autoload.php` (composer)
- [app/src/Security/Session.php](app/src/Security/Session.php) — démarrage de session durci (nouveau)
- [app/src/Security/Csrf.php](app/src/Security/Csrf.php) — protection CSRF (nouveau)
- [app/src/Security/WebAccessGuard.php](app/src/Security/WebAccessGuard.php) — `startSession()` délègue à `Session`
- [app/src/Http/SecurityHeaders.php](app/src/Http/SecurityHeaders.php) — en-têtes de sécurité (nouveau)
- [app/public/index.php](app/public/index.php), [app/public/api.php](app/public/api.php),
  [app/public/login.php](app/public/login.php), [app/public/tariffs.php](app/public/tariffs.php) — câblage en-têtes + CSRF
- [app/templates/login.php](app/templates/login.php), [app/templates/tariffs.php](app/templates/tariffs.php) — champ CSRF
- tests/Unit/Infrastructure/MigrationRunnerTest.php, tests/Unit/Security/CsrfTest.php (nouveaux)

## Étapes
- [x] Runner de migrations versionnées (`schema_migrations`) + CLI `migrate.php` + baseline dans `schema.sql`
- [x] Intégration au déploiement (`deploy_unraid.sh` étape 4)
- [x] Câblage de `vendor/autoload.php` dans `bootstrap.php`
- [x] Démarrage de session durci (HttpOnly, SameSite=Lax, Secure en HTTPS, strict mode)
- [x] Protection CSRF sur les formulaires HTML (login, tarifs)
- [x] En-têtes de sécurité (X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS si HTTPS, CSP Report-Only)
- [x] Tests unitaires + `php -l` + PHPStan niveau 6

## Vérification
- `php -l` sur tous les fichiers modifiés ; PHPStan niveau 6 vert (la CI relance lint multi-versions + PHPStan).
- `php app/scripts/migrate.php --dry-run` → liste les migrations en attente sans rien appliquer.
- Soumission d'un formulaire (login / tarifs) sans jeton CSRF → rejet ; avec jeton valide → OK.
- Inspection des en-têtes de réponse (curl -I) : présence des en-têtes de sécurité + CSP Report-Only.

## Notes
- La **CSP est en Report-Only** : l'app garde des handlers inline (`onclick`/`onsubmit`) ; l'enforcement et le
  nettoyage sont planifiés en **P7**.
- Les migrations historiques sont **baselinées** (non rejouées) car `2026-06-26_drop_redundant_reading_indexes.sql`
  n'est pas idempotente et le schéma de référence les reflète déjà.
