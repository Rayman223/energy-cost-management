# Issue #81 — le 1er compte owner doit être `admin` à l'installation

## Contexte
À l'installation, le premier compte (l'« owner ») était créé avec le rôle `user` alors qu'il
devait être `admin` ([issue #81](https://github.com/Rayman223/Manage-energy-costs/issues/81)).

**Cause** : [UserRepository::create()](../../src/Repository/UserRepository.php#L42) faisait un
`INSERT INTO users` **sans colonne `role`** → le rôle prenait le défaut SQL `'user'`
([schema.sql:104](../../sql/schema.sql#L104)). La promotion admin n'existait que via une migration
one-shot baselinée (jamais rejouée sur base fraîche) ou une étape manuelle documentée. Sur une
installation neuve, l'owner restait donc `user`.

**Résultat attendu** : le tout premier compte créé reçoit `role = 'admin'` ; les suivants restent
`user`. Aucun changement de schéma (la colonne `ENUM('user','admin')` existe déjà).

## Fichiers impactés
- [app/src/Repository/UserRepository.php:42](../../src/Repository/UserRepository.php#L42) — `create()` : `COUNT(*)` dans la transaction, INSERT du rôle (`admin` si 1er compte, sinon `user`).
- [app/src/Repository/Contract/UserRepositoryInterface.php:15](../../src/Repository/Contract/UserRepositoryInterface.php#L15) — docblock du contrat.
- [tests/Fake/FakeUserRepository.php:42](../../../tests/Fake/FakeUserRepository.php#L42) — même règle dans le double de test.
- [tests/Unit/Security/AccountProvisionerTest.php:19](../../../tests/Unit/Security/AccountProvisionerTest.php#L19) — 1er compte → `admin`.
- [tests/Integration/UserRepositoryDbTest.php](../../../tests/Integration/UserRepositoryDbTest.php) — assertions de rôle + `testSetRoleAndStatus` (opère sur un 2e compte pour éviter le no-op de `setRole('admin')`).
- [app/docs/installation.md](../installation.md) — §4/§5 : promotion désormais automatique, SQL manuel en repli.

## Étapes
- [x] `UserRepository::create()` : détection du 1er compte (`COUNT(*)` intra-transaction) + INSERT du rôle
- [x] `FakeUserRepository::create()` : `admin` si aucun user, sinon `user`
- [x] Docblock de l'interface
- [x] Adapter les tests (unit + intégration, dont `testSetRoleAndStatus`)
- [x] Mettre à jour `installation.md`
- [x] Vérifs : `php -l`, PHPUnit (220 OK), PHPStan niv. 5 (No errors)

## Portée / note (hors correctif)
Cas legacy→OIDC : après une install legacy, le compte technique `local/owner` devient admin ; si
l'owner active OIDC ensuite, son nouveau compte OIDC (n'étant plus le 1er) serait `user`. Couvert
par le backfill + la promotion manuelle documentée — hors périmètre de #81.

## Vérification
- `php -l` sur les fichiers modifiés.
- `vendor/bin/phpunit` — `AccountProvisionerTest` vert (assertion `admin`).
- `vendor/bin/phpunit --testsuite integration` (base de test) — `UserRepositoryDbTest` vert.
- `phpstan analyse --configuration=phpstan.dist.neon` — code neuf niveau 5.
- Fonctionnel base fraîche : après 1re création de compte, `SELECT id, role FROM users ORDER BY id;`
  → 1er = `admin`, comptes suivants = `user`.
