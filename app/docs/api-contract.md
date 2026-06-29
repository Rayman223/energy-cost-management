# Contrat de l'API interne (`app/public/api.php`)

> **Référence figée — Phase 0 de l'épopée [#25](https://github.com/Rayman223/Manage-energy-costs/issues/25).**
> Ce document décrit le comportement **actuel** de `api.php` (branche `main`)
> avant le découplage front/back. Il sert de contrat de non-régression pendant le
> refactoring : toute évolution de l'API doit être confrontée à ce document.

## Généralités

- **Point d'entrée unique** : `api.php?action=<action>`. Le verbe HTTP (`GET`/`POST`)
  sélectionne le groupe d'actions.
- **En-têtes de réponse** (toujours) :
  `Content-Type: application/json; charset=utf-8`,
  `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.
- **Encodage JSON** : `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- **Sécurité** : `WebAccessGuard::protect()` est appliqué en amont (liste blanche
  IP/CIDR + HTTP Basic Auth, mode API). Un accès refusé est traité par le guard
  avant tout traitement d'action.
- **Connexion BDD** : un échec d'initialisation PDO renvoie
  `503 { "ok": false, "error": "DB connection failed: …" }`.

### Codes de statut

| Code | Cas |
|------|-----|
| 200  | Succès (les payloads « données » n'ont pas forcément de champ `ok`). |
| 400  | Action inconnue (`Unknown action` en GET, `Unknown POST action` en POST). |
| 405  | Verbe non géré (ni GET ni POST exploitable) → `Method not allowed`. |
| 422  | Validation d'entrée (date invalide, year/month hors borne, valeur compteur invalide, champ requis manquant…). |
| 500  | Exception non gérée → `{ "ok": false, "error": <message> }`. |
| 503  | Connexion base de données impossible. |

### Conventions de réponse

- Les actions **« données »** (`today`, `monthly_delta`, `chart_data`,
  `gas_history`, `water_history`, `sync_status`, `tariffs`) renvoient directement
  l'objet/tableau métier, **sans** enveloppe `{ ok }`.
- Les actions **« estimation de coût »** (`month_cost`, `cost_estimate`,
  `gas_cost`, `gas_month_cost`) renvoient un objet avec `available: bool` ; si
  `false`, un champ `reason` explique pourquoi (voir `CostCalculationService`).
- Les actions **« mutation »** (POST) renvoient `{ "ok": true, … }` en succès et
  `{ "ok": false, "error": … }` en erreur.

---

## Actions GET

| `action` | Paramètres | Réponse (forme) |
|----------|-----------|-----------------|
| `live` | — | `{ ok, timestamp, dries_w?, solar_w?, dries_error?, solar_error? }` — lecture temps réel des compteurs (HomeWizard) ; chaque compteur indisponible ajoute un `*_error` au lieu de faire échouer la requête. |
| `today` | — | Index du jour (`LegacyDailyRepository::getTodayIndexValues`). |
| `monthly_delta` | — | Deltas du mois courant (`getMonthlyDeltas`). |
| `chart_data` | `days` (défaut 30) | Séries journalières pour le graphique (`getDailyDeltasForChart`). |
| `month_cost` | `year` (défaut année courante), `month` (défaut mois courant) | `estimateMonthElectricity(year, month)`. `422` si `year ∉ [2000,2100]` ou `month ∉ [1,12]`. |
| `gas_history` | — | Tous les relevés gaz (`GasRepository::getAllReadings`). |
| `water_history` | — | Tous les relevés eau (`WaterRepository::getAllReadings`). |
| `cost_estimate` | — | `estimateCurrentMonthElectricity()`. |
| `gas_cost` | — | `estimateLastGasPeriod()`. |
| `gas_month_cost` | `year`, `month` (mêmes défauts/validations que `month_cost`) | `estimateMonthGas(year, month)`. |
| `water_month_cost` | `year`, `month` (mêmes défauts/validations que `month_cost`) | `estimateMonthWater(year, month)` — **volume m³ uniquement** (pas de coût : aucun tarif eau). |
| `sync_status` | — | Dates ISO‑8601 du dernier envoi EnergyID par flux (`prelevement_jour`, `prelevement_nuit`, `injection_jour`, `injection_nuit`, `production_solaire`, `gaz_index`, `water_index`) ; `null` si jamais envoyé. |
| `tariffs` | — | `{ electricity: [...], gas: [...] }` ; chaque grille : `{ id, name, valid_from (Y-m-d), valid_to (Y-m-d|null), lines }`. |
| *(autre)* | — | `400 { ok:false, error:"Unknown action" }`. |

### Forme d'une estimation électricité (`cost_estimate`, `month_cost`)

```jsonc
{
  "available": true,
  "period_from": "…", "period_to": "…",
  "days": 30,
  "tariff_name": "…",
  "tariff_rates": { /* lignes du tarif */ },
  "deltas": { /* prelev_jour, prelev_nuit, injec_jour, injec_nuit, solar, from, to */ },
  "cost": { /* sortie de TariffCalculatorService::calculateElectricityCost */ }
}
// ou, si indisponible :
{ "available": false, "reason": "No data for current month" }
```

### Forme d'une estimation gaz (`gas_cost`, `gas_month_cost`)

`available: true` avec `period_from/to`, `days`, `delta_m3`, `kwh`,
`pcs_coefficient`, `tariff_name`, `tariff_rates`, `cost` (sortie de
`calculateGasCost`). `gas_month_cost` ajoute `month_start/end`, `calendar_days`,
`is_projection` (true quand le mois en cours est projeté jusqu'à sa fin). Sinon
`{ available:false, reason }`.

La consommation mensuelle est **interpolée à minuit** le 1er du mois et le 1er du
mois suivant (cf. `MonthlyConsumptionInterpolator`) : le décalage horaire des
relevés manuels est récupéré par extrapolation aux bornes, et les relevés
intermédiaires servent d'ancrages. Même méthode pour l'électricité (`month_cost`)
et l'eau (`water_month_cost`).

### Forme d'une consommation eau (`water_month_cost`)

`available: true` avec `period_from/to`, `month_start/end`, `days`,
`calendar_days`, `is_projection`, `delta_m3` (**volume m³, sans coût** : l'eau n'a
pas de tarif). Sinon `{ available:false, reason }` (ex. « Relevé manquant : le
calcul se fera dès le prochain relevé. »).

---

## Actions POST

Corps **JSON** (`Content-Type: application/json`). Un corps illisible est traité
comme `{}`.

| `action` | Corps attendu | Réponse succès | Validations (→ 422) |
|----------|---------------|----------------|---------------------|
| `gas_entry` | `{ counter_m3: float>0, reading_at?: date }` | `{ ok:true, saved_at (ISO), counter_m3 }` | `counter_m3` invalide/≤0 ; date invalide ; date ≤ dernier relevé ; `counter_m3` < dernier relevé. |
| `water_entry` | `{ counter_m3: float>0, reading_at?: date }` | `{ ok:true, saved_at (ISO), counter_m3 }` | idem `gas_entry`. |
| `save_tariff` | `{ energy_type: "electricity"\|"gas", name, valid_from: date, valid_to?: date, lines: object }` | `{ ok:true, id }` | champ requis manquant (`energy_type`, `name`, `valid_from`, `lines`) ; `energy_type` hors énum ; `valid_from`/`valid_to` invalides. |
| *(autre)* | — | — | `400 { ok:false, error:"Unknown POST action" }`. |

`reading_at` absent ⇒ horodatage `now`. Les bornes anti-régression compteur
(date croissante, index croissant) sont vérifiées contre `getLatest()`.

---

## Couverture de tests (Phase 0)

La logique métier derrière les actions de coût est couverte par
`tests/Unit/Service/CostCalculationServiceTest.php` (via fakes des repositories)
et `tests/Unit/Service/TariffCalculatorServiceTest.php` (calculs purs).
Les tests **HTTP de bout en bout** de `api.php` (serveur + base) sont **reportés
en Phase 3**, après extraction des contrôleurs.
