# Issue #88 — tarif électricité : choix tarif fixe ou dynamique

## Contexte

L'utilisateur doit pouvoir **choisir** son type de tarification électricité :
**classique (fixe)**, **dynamique horaire (1 h)** ou **dynamique 15 min**. En mode
dynamique, les lignes d'énergie fournisseur de la grille tarifaire
(`energy_simple` / `energy_t1` / `energy_t2`) ne servent plus (le prix énergie
vient d'ENTSO-E) ; les **autres lignes** (abonnement, distribution, taxes,
accises, injection) restent utilisées.

Précision issue : ENTSO-E fournit un **prix horaire natif (PT60M)** distinct de la
moyenne des points 15 min → le mode 1 h doit utiliser ce prix natif, pas une
moyenne. Lien : https://github.com/Rayman223/Manage-energy-costs/issues/88

Décisions validées :
- Choix stocké sur le **profil utilisateur** (`user_profiles.pricing_mode`).
- Sélecteur à **3 modes** ; calcul **15 min différé** (retombe sur l'horaire), seul
  le **1 h natif** est câblé dans cette issue.
- Dashboard : **garder les deux** coûts (fixe + dynamique) côte à côte.
- Formulaire de grille : lignes énergie **grisées + note** (non supprimées).

## Fichiers impactés

- [app/sql/migrations/2026-07-11_pricing_mode_and_native_hourly.sql](app/sql/migrations/2026-07-11_pricing_mode_and_native_hourly.sql) — colonne `pricing_mode` + reclé unique `dynamic_prices` (ajoute `resolution_min`).
- [app/sql/schema.sql:82](app/sql/schema.sql#L82), [app/sql/schema.sql:123](app/sql/schema.sql#L123) — baseline.
- [app/src/Repository/UserRepository.php:111](app/src/Repository/UserRepository.php#L111) — `PRICING_MODES`, `updateProfile()`/`getProfile()` gèrent `pricing_mode`.
- [app/src/Repository/Contract/UserRepositoryInterface.php:29](app/src/Repository/Contract/UserRepositoryInterface.php#L29) — forme `@return` de `getProfile`.
- [app/public/account.php:94](app/public/account.php#L94) + [app/templates/account.php](app/templates/account.php) — lecture/validation + sélecteur 3 modes.
- [app/public/tariffs.php:388](app/public/tariffs.php#L388) + [app/templates/tariffs.php](app/templates/tariffs.php) + [app/public/assets/css/tariffs.css](app/public/assets/css/tariffs.css) — grisage lignes énergie (`ComponentKind::isSupplierEnergy()`).
- [app/src/Repository/Contract/DynamicPriceRepositoryInterface.php](app/src/Repository/Contract/DynamicPriceRepositoryInterface.php) + [app/src/Repository/DynamicPriceRepository.php:86](app/src/Repository/DynamicPriceRepository.php#L86) — `getHourlyPrices()` natif (resolution_min = 60).
- [app/src/Service/CostCalculationService.php:395](app/src/Service/CostCalculationService.php#L395) — prix horaire natif (repli moyenne) + `resolution`/`price_source`/`pricing_mode`; param constructeur `pricingMode`.
- [app/public/index.php:73](app/public/index.php#L73), [app/public/api.php:99](app/public/api.php#L99) — plombent `pricing_mode`.
- [tests/Fake/FakeDynamicPriceRepository.php](tests/Fake/FakeDynamicPriceRepository.php) + [tests/Unit/Service/CostCalculationServiceTest.php](tests/Unit/Service/CostCalculationServiceTest.php) — natif vs repli.
- i18n : [fr](app/translations/fr.php), [en](app/translations/en.php), [nl](app/translations/nl.php), [de](app/translations/de.php).

## Étapes

- [x] Migration SQL (`pricing_mode` + reclé unique) + schema.sql.
- [x] `UserRepository` + interface : `pricing_mode`.
- [x] `account.php` + template : sélecteur 3 modes.
- [x] `tariffs.php` + template + CSS : grisage lignes énergie en dynamique.
- [x] `DynamicPriceRepository::getHourlyPrices()` natif + interface + fake.
- [x] `CostCalculationService` : prix natif (repli moyenne) + plomber `pricing_mode`.
- [x] i18n (fr/en/nl/de).
- [x] Vérifs : `php -l`, PHPStan niveau 5, PHPUnit (+ 2 tests natif/repli).

## Vérification

- `php -l` OK sur tous les fichiers modifiés.
- PHPStan niveau 5 : **No errors**.
- PHPUnit : **239 tests OK** (39 skip = intégration nécessitant la BDD).
- Manuel (BDD requise) : `/account` change de mode → persistance ; `/tariffs` en
  dynamique grise les lignes énergie ; dashboard montre fixe + dynamique ; en 1 h,
  le prix énergie = prix horaire natif (≠ moyenne 15 min) ; migration idempotente.

## Points d'attention / suite

- **15 min** différé : sélectionnable mais retombe sur l'horaire (`resolution = hourly`).
  Reste à faire (ticket ultérieur) : lecture prix 15 min natifs + bucketisation
  conso au quart d'heure + boucle de matching.
- `getHourlyPrices()` ne renvoie que si des lignes `resolution_min = 60` existent ;
  vérifier que le cron/parser récupèrent bien une série PT60M pour la zone (sinon
  repli moyenne — pas de régression).
