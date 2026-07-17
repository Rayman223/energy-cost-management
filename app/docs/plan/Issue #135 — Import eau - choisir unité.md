# Issue #135 — Import CSV : choisir l'unité

## Contexte
À l'import CSV, les valeurs étaient écrites telles quelles en supposant l'unité
canonique (électricité = kWh, gaz/eau = m³). Un compteur d'eau exporté en
**litres** (ou un export élec en **Wh**) était donc importé avec un facteur 1000
d'erreur. On ajoute un sélecteur d'unité, dépendant du type d'énergie, et une
conversion vers l'unité canonique avant écriture.

Unités proposées : Électricité → Wh/kWh · Gaz → m³ · Eau → litre/m³.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/135

## Fichiers impactés
- [ImportMapping.php](../../src/Service/Import/ImportMapping.php) — `UNITS` (source
  unique par type) + `UNIT_FACTORS`, propriété `unitToCanonicalFactor`, surcharge
  `unit` validée dans `unitFactor()`.
- [ImportRunner.php](../../src/Service/Import/ImportRunner.php) — extraction du
  POST `unit` en override.
- [BulkImportService.php](../../src/Service/BulkImportService.php) — applique le
  facteur avant `saveIgnore()` (eau/gaz) et `insertIndexes()` (élec).
- [account.php](../../templates/account.php) — `<select name="unit">` + bloc JSON
  `#import-units` + inclusion `import.js`.
- app/public/assets/js/import.js — bascule des options selon `energy_type` (CSP-safe).
- app/translations/{fr,en,de,nl}.php — clés `import.unit*`, `import.value_col`
  neutralisée.

## Étapes
- [x] `ImportMapping` : unités + facteur de conversion (validation stricte)
- [x] `ImportRunner` : lecture du POST `unit`
- [x] `BulkImportService` : conversion élec (Wh→kWh) + utilitaire (litre→m³)
- [x] Template : select + data block + asset ; JS de bascule
- [x] Traductions 4 langues
- [x] Tests unitaires (facteurs + conversion appliquée)

## Vérification
- `php -l` OK sur tous les fichiers ; PHPStan niveau 6 vert ; PHPUnit 300/300.
- Eau en litres : 1500 L → `counter_m3` = 1.5 (÷1000) ; en m³ → 1500 inchangé.
- Élec en Wh : 2 000 000 Wh → registre 2000 kWh ; en kWh inchangé.
- Gaz : seule unité m³ ; unité invalide pour un type → `InvalidArgumentException`
  (message assaini côté web).
- UI : changer le type met à jour les options d'unité ; sans JS, l'électricité
  reste importable (options par défaut rendues côté serveur).
- CLI (`import_eau.php`/`import_gaz.php`) inchangé : facteur 1 par défaut.
