# Issue #25 — Phase 4 : Interfaces repos + tests des contrôleurs

## Contexte

Épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25),
Phase 4, point « interfaces sur les repos restants pour tester les contrôleurs ».
Les contrôleurs HTTP (Phase 3) dépendaient de repositories **concrets** (`final` +
PDO) → non testables avec des fakes. On étend l'approche Phase 0 (seams par
interface) pour rendre testables les contrôleurs à **vraie logique de validation**.

## Changements

- **[MeterReadingRepositoryInterface](app/src/Repository/Contract/MeterReadingRepositoryInterface.php)**
  (nouveau) : `save()`, `getAllReadings()`, `getLatest()`. **GasRepository** et
  **WaterRepository** l'implémentent (annotations identiques).
- **[TariffRepositoryInterface](app/src/Repository/Contract/TariffRepositoryInterface.php)**
  étendu : `findAll()`, `saveGrid()` (en plus de `findActiveGrid`/`findMostRecentPcs`).
- Contrôleurs type-hintés sur les interfaces (additif, le câblage `api.php` passe
  toujours les repos concrets) :
  - `MeterEntryController` : `MeterReadingRepositoryInterface` (remplace l'union
    `GasRepository|WaterRepository`).
  - `TariffController` : `TariffRepositoryInterface`.

## Tests (via fakes, sans base)

- `FakeMeterReadingRepository` (nouveau), `FakeTariffRepository` étendu (findAll/saveGrid).
- `MeterEntryControllerTest` : counter ≤ 0, date invalide, succès + `save()` appelé,
  date ≤ dernier relevé, index < dernier, routage eau.
- `TariffControllerTest` : champ requis manquant, `energy_type` invalide, date
  invalide, persistance + id retourné, mapping `index()`.

## Vérification

- PHPStan **niveau 6 : 0 erreur** (les repos concrets satisfont les interfaces).
- `php -l` OK ; PHPUnit **59 tests / 261 assertions** (+11).

## Suite (reste Phase 4)

- Découpe des gros services (`CostCalculationService` gardé par tests ;
  `DailyLegacyWebhookSyncService` → tests de caractérisation d'abord).
- `ReadingsController` (délégation triviale) : interface Legacy + tests si souhaité.
