# Issue #59 — P8 : Import en masse de données (bulk import)

Dernière phase de l'épopée #47. Import fiable de grandes quantités d'index
(CSV/JSON), self-service pour tout membre + capacité admin « pour le compte d'un
autre ». Traitement **synchrone en flux**, mapping **presets + surcharge**.

## Livré

- **Service partagé** `App\Service\BulkImportService` : streaming, validation par
  ligne (collecte des erreurs sans interrompre), upsert idempotent via les seams
  d'ingestion (`insertIndexes` / `saveIgnore`, `INSERT IGNORE`), rapport
  `imported/duplicates/errors`.
- **Value objects** `App\Service\Import\` : `RowSource` (CSV flux / JSON),
  `ImportMapping` (presets + surcharge, registres depuis
  `MeterTopology::ELECTRICITY_REGISTERS`), `ImportReport`, `ImportTarget`
  (autorisation self/admin), `ImportRunner` (upload web).
- **CLI** : `import_readings.php` (générique élec/gaz/eau, `--user/--file/--type/
  --ts-col/--value-col/--map/--execute`, dry-run par défaut) ; `import_gaz.php` /
  `import_eau.php` refactorés en fines enveloppes déléguant au service.
- **UI** : upload self-service (page compte, cible = soi) et upload admin (page
  admin, sélecteur d'utilisateur cible, gardé par `ImportTarget`).
- **i18n** `import.*` (fr/en/nl/de). **Doc** [import.md](../import.md).

## Autorisation (critère d'acceptation clé)

`ImportTarget::resolve(currentUserId, isAdmin, requestedTargetId)` :
importer pour soi = toujours OK ; importer pour autrui = **admin requis**. Le
self-service ne lit aucun `target_user_id` (cible forcée = utilisateur courant),
donc un non-admin ne peut jamais importer pour un tiers, même en trafiquant le
POST. La page admin est déjà entièrement réservée aux admins.

## Tests

- **Unit** : `BulkImportServiceTest` (compteurs, idempotence, surcharge de
  colonne, erreurs collectées), `RowSourceTest` (CSV/JSON), `ImportMappingTest`
  (presets/surcharges/validation), `ImportTargetTest` (autorisation).
- **Intégration BDD** : `BulkImportDbTest` (import gaz + électricité, réimport →
  doublons, scope d'un autre utilisateur).

## Hors périmètre (suivi)

File de jobs asynchrone (fichiers très volumineux) ; constructeur de mapping
dynamique complet dans l'UI.
