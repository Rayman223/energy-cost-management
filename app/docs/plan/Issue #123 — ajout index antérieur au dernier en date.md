# Issue #123 — Autoriser l'ajout d'un index antérieur au dernier relevé (électricité, gaz, eau)

## Contexte

La saisie manuelle gaz/eau rejetait tout relevé daté avant le dernier enregistré : la
validation comparait systématiquement au relevé le plus récent (`getLatest()`, trié par date
DESC). Voir l'ancienne logique de [MeterEntryController::saveReading()](../../src/Http/Controller/MeterEntryController.php) :
la date devait être postérieure au dernier **et** la valeur ≥ à celle du dernier. D'où l'erreur
dès qu'on antidatait. Le chemin électricité (`electricity()` → `insertIndexes()`) n'avait, lui,
aucune validation de cohérence.

Besoin (issue #123) : pouvoir insérer un relevé dans le passé, à condition que la valeur reste
croissante chronologiquement — bornée entre le relevé précédent et le relevé suivant à la date
saisie (bornes inclusives : un compteur peut rester stable). Règle appliquée aux **3 fluides**.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/123

## Fichiers impactés
- [app/src/Http/Controller/MeterEntryController.php](../../src/Http/Controller/MeterEntryController.php) — validation encadrante (gaz/eau + électricité par registre)
- [app/src/Repository/Contract/MeterReadingRepositoryInterface.php](../../src/Repository/Contract/MeterReadingRepositoryInterface.php) — `getReadingBefore()` / `getReadingAfter()`
- [app/src/Repository/UtilityReadingRepository.php](../../src/Repository/UtilityReadingRepository.php) — implémentation gaz/eau
- [app/src/Repository/Contract/ElectricityIngestionInterface.php](../../src/Repository/Contract/ElectricityIngestionInterface.php) — `readingBounds()`
- [app/src/Repository/ElectricityReadingRepository.php](../../src/Repository/ElectricityReadingRepository.php) — implémentation bornes par registre
- Tests : `tests/Fake/*`, `tests/Unit/Http/MeterEntryControllerTest.php`, `tests/Integration/*DbTest.php`

## Étapes
- [x] Gaz/eau : `getReadingBefore`/`getReadingAfter` (repo + interface) + validation encadrante dans `saveReading()`
- [x] Électricité : `readingBounds()` (repo + interface, sans toucher `insertIndexes()`) + validation dans `electricity()`
- [x] Doubles de test (`FakeMeterReadingRepository`, `FakeElectricityIngestion`)
- [x] Tests unitaires (contrôleur) + intégration (repos)
- [x] `php -l`, PHPUnit, PHPStan niveau 5

## Vérification
- Automatique : `vendor/bin/phpunit` (249 tests OK), PHPStan 2.2.2 niveau 5 (`No errors`), `php -l`.
- End-to-end (`/meter-readings`) avec deux relevés encadrant une date :
  1. Relevé gaz antidaté, valeur entre les deux encadrants → succès (auparavant : erreur « after the latest entry »).
  2. Valeur < relevé précédent → « must be ≥ previous reading ».
  3. Valeur > relevé suivant → « must be ≤ next reading ».
  4. Rejouer un horodatage existant → « already exists at this date ».
  5. Idem eau et électricité (par registre).
