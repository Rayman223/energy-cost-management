# Issue #134 — Import csv électricité

## Contexte

Deux reproches dans l'issue : « pour l'import en csv, il n'est pas facile de
comprendre comment le système fonctionne », et l'impossibilité, pour l'électricité,
de **choisir quel index on importe** (heure pleine / creuse / normale / injection
pleine / injection creuse / solaire) puis d'en importer **plusieurs d'un coup**
depuis un CSV qui les contient tous.

Le moteur savait déjà le faire : `ImportMapping` accepte une surcharge `registers`
(colonne → register_key) et `BulkImportService::importElectricity()` boucle sur
toutes les colonnes mappées. **Le trou était dans l'UI web** : le formulaire
n'offrait que `ts_col` / `value_col`, et `value_col` est ignoré pour l'électricité —
faute de surcharge, les colonnes devaient s'appeler exactement `import_t1`,
`import_t2`, `export_t1`, `export_t2`, `production`. Le mapping n'était atteignable
que par `--map=col:reg` en CLI.

Deux verrous voisins bloquaient l'usage réel (exports tableur FR/BE), corrigés ici
car sans eux le mapping resterait inopérant : le **délimiteur figé à `,`** (le
paramètre existait mais aucun appelant ne le passait — un CSV en `;` échouait sur
chaque ligne avec le message trompeur « horodatage absent ») et le **BOM UTF-8**,
qui restait collé au 1er nom de colonne.

Lien : https://github.com/Rayman223/Manage-energy-costs/issues/134

## Fichiers impactés

- [app/src/Service/Import/RowSource.php:25](../../src/Service/Import/RowSource.php#L25) — auto-détection du délimiteur + retrait du BOM
- [app/src/Service/Import/ImportRunner.php:42](../../src/Service/Import/ImportRunner.php#L42) — `parseOverrides()` : lit `registers[]` du POST et l'inverse
- [app/templates/account.php:236](../../../templates/account.php#L236) — fieldset « Index à importer » + datalist
- [app/public/assets/js/import.js](../../../public/assets/js/import.js) — en-tête CSV → autocomplétion, bascule élec/gaz-eau
- [app/public/assets/css/account.css:15](../../../public/assets/css/account.css#L15) — style du fieldset
- `app/translations/{fr,en,de,nl}.php` — libellés des index + aide révisée
- `tests/Unit/Service/{RowSourceTest,ImportRunnerTest}.php`, `tests/Integration/ImportRunnerDbTest.php`
- [app/docs/import.md](../import.md) — formats & mapping

`ImportMapping` et `BulkImportService` sont inchangés : l'UI se branche sur des
seams existants.

## Étapes

- [x] `RowSource::fromCsv($handle, ?string $delimiter = null)` — détection `,` / `;` / tabulation sur l'en-tête (le délimiteur qui découpe le plus de colonnes gagne, égalité → `,`), via `str_getcsv` pour qu'un délimiteur présent dans un champ quoté ne fausse pas le score. Retrait du BOM UTF-8. Délimiteur explicite toujours prioritaire (CLI).
- [x] `ImportRunner::parseOverrides(array $post): array` — publique et statique (testable sans PDO ni upload). Inverse `registre => colonne` en `colonne => registre`, écarte les champs vides, refuse une colonne affectée à deux index, et n'émet pas de surcharge `registers` si rien n'est renseigné (préserve le défaut du preset).
- [x] Formulaire : fieldset « Index à importer », un champ par registre issu de `MeterTopology::ELECTRICITY_REGISTERS` (pas de liste recopiée), `list="import-columns"`, placeholder = clé du registre. `value_col` isolé dans `#import-value-col`.
- [x] `import.js` : bascule élec/gaz-eau, lecture des 64 premiers Ko du CSV choisi (`FileReader`), même heuristique de délimiteur qu'en PHP, remplissage du `<datalist>`, pré-remplissage **uniquement** sur correspondance exacte du nom d'index.
- [x] i18n × 4 langues : `import.columns_title`, `import.columns_hint`, `import.reg_*`. Mono-horaire traité par le libellé d'`import_t1` (« Prélèvement heure pleine (ou mono-horaire) »), sans nouveau registre.
- [x] Tests : délimiteurs (`;`, tabulation, forcé, quoté), BOM ; `parseOverrides` (inversion, vides, doublon de colonne) ; intégration du POST web complet.
- [x] Doc `import.md` + docblock périmé d'`ImportRunner` (voie « admin » retirée en #90).

## Vérification

- `php -l` sur les fichiers modifiés ; **PHPStan niveau 6** : aucune erreur.
- **PHPUnit : 313 tests / 2754 assertions verts** (unitaires + intégration sur base réelle).
- Test d'intégration `testWebRequestImportsSeveralRegistersFromOneCsv` — le scénario
  exact de l'issue par la voie web (`runFromRequest`) : CSV en `;` aux colonnes
  `Date;HP_Jour;HP_Nuit;Inj_Jour`, 3 index mappés → 6 index écrits (2 lignes × 3),
  valeurs contrôlées registre par registre, réimport → 0 importé / 3 doublons.
- Template rendu hors serveur dans les 4 langues : 5/5 champs de registre, datalist
  et fieldset présents, 0 clé non traduite.
- `import.js` exécuté dans un stub DOM (Node) : bascule des types, détection `;` /
  `,` / tabulation / BOM / virgule quotée, pré-remplissage canonique, JSON ignoré.

### À la main

1. « Mon compte » → Électricité, kWh → choisir un CSV `Date;HP_Jour;HP_Nuit` →
   l'autocomplétion des champs « Index à importer » propose `date`, `hp_jour`, … ;
   mapper puis **Simulation** → bilan sans écriture. Décocher → import réel.
2. Basculer sur Gaz : le fieldset des index disparaît, « Colonne valeur » revient.
3. JS désactivé : le formulaire reste soumettable en saisie libre.

## Suivi (hors périmètre)

- **Décimales à la virgule** ([#150](https://github.com/Rayman223/Manage-energy-costs/issues/150)) :
  `ReadingParser::parseValue()` s'appuie sur `FILTER_VALIDATE_FLOAT` et rejette
  `1234,5`. Un export tableur FR/BE en `;` porte presque toujours des décimales à la
  virgule ; l'erreur est désormais *exacte* (« valeur invalide (1234,5) ») au lieu
  d'être trompeuse, mais le fichier échoue toujours. Changement de sémantique du
  parsing des valeurs — et l'ambiguïté `1,234` (milliers ou décimale ?) n'existe que
  sur les fichiers délimités par `,`, ce que la détection de délimiteur ajoutée ici
  permet justement de trancher.
