# Issue #25 — Phase 4 : Durcissement PHPStan (niveau 5 → 6)

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 4, point « renforcer le typage / niveau supérieur ». On passe l'analyse
statique du **niveau 5 au niveau 6**, qui exige un **type de valeur sur tous les
`array`** (`missingType.iterableValue`).

## Changements

- **48 annotations** `@param array<…>` / `@return array<…>` ajoutées sur ~15
  fichiers (`src/`), en gardant les éléments en `mixed` là où le code les
  consomme déjà comme tels → **aucune cascade, aucun changement de comportement**.
  Types plus précis quand trivialement sûrs :
  - `TariffGrid::toTariffArray()` → `array<string, float>`.
  - Paires de relevés (`getLastTwoReadings`/`getTwoReadingsForMonth`) →
    `array{from: array<string, mixed>|null, to: array<string, mixed>|null}`.
  - `DailyLegacyWebhookSyncService::syncUntil()` → `list<array<string, mixed>>`.
  - `HttpClient::postJson()` / `EnergyIdV2Client::postMeasurements()` `$payload`
    → `array<array-key, mixed>` (objet JSON **ou** liste de mesures).
- [phpstan.dist.neon](phpstan.dist.neon) : `level: 6`.
- [phpstan-baseline.neon](phpstan-baseline.neon) : message de l'entrée
  `postWithRetry` by-ref mis à jour (le type de `$session` est désormais explicite).
  Cette dette de typage by-ref reste baselinée — elle relève de la **découpe du
  service webhook** (autre point Phase 4).

## Vérification

- PHPStan **niveau 6 : 0 erreur** (baseline incluse, aucune entrée non-matchée).
- `php -l` OK sur les 15 fichiers.
- PHPUnit **48 tests / 223 assertions** (annotations PHPDoc → zéro régression).

## Suite (reste Phase 4)

- Découpe des gros services (`CostCalculationService`, `DailyLegacyWebhookSyncService` —
  résorberait la dette by-ref de `$session`).
- Interfaces sur les repos restants pour tester `ReadingsController`/`TariffController`.
