# Issue #150 — Import CSV : les valeurs à décimale virgule (« 1234,5 ») sont rejetées

## Contexte

Découvert en marge de #134 (PR #149). Depuis #134 le séparateur `;` des exports
tableur FR/BE est bien détecté et les colonnes bien mappées — mais chaque ligne
échoue quand même, car les valeurs sortent presque toujours avec une **décimale à
la virgule** (`1234,5`, `0,75`). [ReadingParser::parseValue()](app/src/Service/Import/ReadingParser.php#L30)
s'appuie sur `FILTER_VALIDATE_FLOAT` sans `FILTER_FLAG_ALLOW_THOUSAND` et rejette
toute virgule.

L'ambiguïté `1,234` (millier anglo-saxon vs décimale FR) n'existe que sur les
fichiers délimités par `,`. Le contexte tranche : un fichier délimité par `;` ou
tabulation ne peut contenir qu'une **décimale** dans ses virgules — c'est exactement
le signal que [RowSource::sniffDelimiter()](app/src/Service/Import/RowSource.php#L92)
calcule déjà (#134). Décision retenue (« À décider » de l'issue) : **approche
stricte** — on n'accepte que `-?\d+,\d+` ; les séparateurs de milliers (`1 234,5`,
`1.234,5`) restent rejetés avec le message d'erreur existant.

**Contrainte clé** : ne PAS toucher `ReadingParser` — prédicat strict partagé avec
l'API d'ingestion ([IngestController](app/src/Http/Controller/IngestController.php)),
où la sévérité est un choix délibéré. La conversion est une affaire de **présentation
CSV**, pas de validation métier.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/150

## Fichiers impactés

- [app/src/Service/Import/RowSource.php:50](app/src/Service/Import/RowSource.php#L50) — drapeau `$commaIsDecimal` + normalisation par cellule dans `fromCsv()`, helper `normalizeDecimal()`.
- [tests/Unit/Service/RowSourceTest.php](tests/Unit/Service/RowSourceTest.php) — cas `;`/tab acceptés, `,` non réinterprété, non-régression `.`, milliers laissés intacts.
- [app/docs/import.md:27](app/docs/import.md#L27) — section « Formats & mapping ».

## Étapes

- [x] `RowSource::fromCsv()` : `$commaIsDecimal = $delimiter !== ','` ; chaque cellule passe par `normalizeDecimal()` (motif étroit `/^-?\d+,\d+$/` → virgule remplacée par un point).
- [x] `ReadingParser::parseValue()` inchangé (API strict).
- [x] Tests unitaires RowSource (accepté en `;`/tab, rejeté en `,`, non-régression `.`, milliers intacts, horodatage intact).
- [x] `app/docs/import.md` — précision sur la décimale virgule inférée du délimiteur.

## Vérification

- `php -l app/src/Service/Import/RowSource.php` → OK.
- `vendor/bin/phpunit tests/Unit` → 265 tests OK (dont 20 sur RowSource).
- PHPStan niveau 6 (`normalizeDecimal` typé `string→string`) relayé à la CI (PHAR 2.2.2 non installé en local).
- End-to-end : importer un CSV `;` avec `hp_jour = 1234,5` → ligne acceptée au lieu de « valeur invalide ».
