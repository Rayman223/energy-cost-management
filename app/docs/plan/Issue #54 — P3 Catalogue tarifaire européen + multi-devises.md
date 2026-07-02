# Issue #54 — P3 Catalogue tarifaire européen + multi-devises

## Contexte
Quatrième phase de l'épopée #47. Le modèle tarifaire devient européen et communautaire : un **catalogue partagé**
(grilles `user_id NULL`, gérées par un admin) que chaque membre peut **surcharger** par ses propres grilles ;
**multi-devises** (ISO 4217, EUR par défaut, sans conversion) ; **prix dynamiques ENTSO-E par zone de marché**.
Lien : https://github.com/Rayman223/Manage-energy-costs/issues/54

## Fichiers impactés
- [app/sql/migrations/2026-07-03_tariffs_eu.sql](app/sql/migrations/2026-07-03_tariffs_eu.sql) + [app/sql/schema.sql](app/sql/schema.sql)
  — `tariff_grids` : `user_id` (NULL = catalogue partagé), `country`, `currency` ; `dynamic_prices` : `bidding_zone`
  (+ unicité composite) ; **le premier compte devient admin**. Les grilles existantes deviennent le catalogue initial.
- [app/src/Domain/TariffGrid.php](app/src/Domain/TariffGrid.php) — `userId`/`country`/`currency` + `isShared()`.
- [app/src/Repository/TariffRepository.php](app/src/Repository/TariffRepository.php) — scopé `(userId, isAdmin)` :
  résolution **perso > partagé** ; les grilles perso des autres sont invisibles et non modifiables (« introuvable ») ;
  seules les grilles partagées exigent le rôle admin.
- [app/src/Repository/DynamicPriceRepository.php](app/src/Repository/DynamicPriceRepository.php) — scopé par zone.
- [app/scripts/cron_dynamic_prices.php](app/scripts/cron_dynamic_prices.php) — fetch ENTSO-E **multi-zones**
  (zones des profils utilisateurs ∪ zone de config).
- [app/src/Service/CostCalculationService.php](app/src/Service/CostCalculationService.php) — `currency` dans les
  4 retours de coûts.
- [app/public/tariffs.php](app/public/tariffs.php) + [app/templates/tariffs.php](app/templates/tariffs.php) — champs
  pays/devise, checkbox « catalogue partagé » (admin), badge « Partagé » ; validation ISO.
- [app/public/api.php](app/public/api.php), [app/public/index.php](app/public/index.php) — wiring (isAdmin, zone du
  profil), [TariffController](app/src/Http/Controller/TariffController.php) expose country/currency/shared.
- tests/Integration/TariffRepositoryDbTest.php (nouveau).

## Étapes
- [x] Migration + schema.sql (baseline) ; grilles existantes → catalogue partagé ; 1er compte → admin.
- [x] Résolution perso > partagé + gardes de propriété/rôle.
- [x] Multi-devises (persistance + retours de coûts + UI).
- [x] ENTSO-E par bidding zone (repo scopé, cron multi-zones, zone du profil au wiring).
- [x] Tests d'intégration (priorité perso, invisibilité cross-tenant, garde admin, persistance devise).

## Vérification
- CI (PHPUnit unit + intégration MariaDB).
- Dashboard : coûts inchangés pour l'owner (ses grilles = catalogue partagé, EUR).
- tariffs.php : un membre voit le catalogue + ses grilles ; un admin peut publier au catalogue.

## Notes / reports
- La **sélection explicite** d'une grille du catalogue (plusieurs fournisseurs) viendra avec la page compte (**P5**) ;
  en P3, la surcharge perso prime sinon catalogue (grille la plus récente).
- Formatage localisé des montants (symbole devise…) → **P6** (i18n). Le front affiche la devise brute pour l'instant.
- TVA par pays : portée par les lignes de grilles (structure flexible existante) ; presets par pays → P5/P7 (admin).
