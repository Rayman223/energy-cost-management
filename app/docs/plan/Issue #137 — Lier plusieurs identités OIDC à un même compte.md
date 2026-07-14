# Issue #137 — Lier plusieurs identités OIDC à un même compte

## Contexte

Depuis le multi-fournisseurs OIDC (#122), un utilisateur qui se connecte avec
Google **puis** Microsoft obtient deux comptes distincts (identité = couple
`(oidc_iss, oidc_sub)` + `uq_users_oidc`, aucun e-mail stocké → RGPD). Cette
issue permet de **lier plusieurs identités à un seul compte** pour éviter les
doublons. Hors périmètre : la **fusion** de deux comptes déjà peuplés.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/137

`user_identities` devient la **source de vérité** de la recherche `(iss, sub)`.
`users.oidc_iss/sub/provider` restent l'**identité primaire** (rétro-compat,
non-cassant) et pointent toujours vers l'une des lignes `user_identities` du
compte. À la déliaison de la primaire, une autre identité est **promue**
(invariant préservé → pas de collision `uq_users_oidc` au re-provisioning).

## Fichiers impactés

- [app/sql/migrations/2026-07-14_user_identities.sql](../../sql/migrations/2026-07-14_user_identities.sql) — table + backfill idempotent.
- [app/sql/schema.sql](../../sql/schema.sql) — table + baseline `schema_migrations`.
- [app/src/Repository/UserIdentityRepository.php](../../src/Repository/UserIdentityRepository.php) (+ interface `Contract/UserIdentityRepositoryInterface`).
- [app/src/Repository/UserRepository.php](../../src/Repository/UserRepository.php) — `findByOidc()` via JOIN, `create()` sème l'identité, `setPrimaryIdentity()`.
- [app/src/Security/IdentityLinker.php](../../src/Security/IdentityLinker.php) — statuts `linked` / `already_linked_self` / `taken_by_other`.
- [app/routes/auth/login.php](../../routes/auth/login.php) — armement (`?link=1`) + mode liaison au callback.
- [app/routes/account.php](../../routes/account.php) + [app/templates/account.php](../../templates/account.php) — carte « Fournisseurs de connexion », actions link/unlink, statuts GET.
- [app/translations/*.php](../../translations/) — clés `account.identity*` (fr/en/de/nl).

## Étapes
- [x] Migration `user_identities` + backfill + schema.sql
- [x] `UserIdentityRepository` (+ interface) ; `findByOidc` JOIN ; `create()` identité ; `setPrimaryIdentity`
- [x] Service `IdentityLinker`
- [x] Flux auth/callback (mode liaison)
- [x] Page compte : UI + actions unlink/link + statuts GET
- [x] Traductions fr/en/de/nl
- [x] Tests : `IdentityLinkerTest` (unit) + `UserIdentityRepositoryDbTest` (intégration)

## Vérification
- `php -l` sur tous les fichiers modifiés — OK.
- PHPStan niveau 6 (`phpstan.dist.neon`) — OK, sans baseline.
- PHPUnit unit — OK (233 tests). Intégration BDD → job CI `tests-db`.
- Manuel : connexion Google → lier Microsoft → reconnexion via l'un/l'autre =
  même compte ; délier la primaire → promotion ; refus dernière identité ; refus
  d'une identité déjà rattachée à un autre compte.
