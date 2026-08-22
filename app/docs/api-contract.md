# Contrat de l'API interne (`app/routes/api.php`, route `/api`)

> **Référence figée — Phase 0 de la refonte initiale.**
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
  `*_monthly_series`, `tariffs`) renvoient directement l'objet/tableau métier,
  **sans** enveloppe `{ ok }`.
- Les actions **« historique paginé »** (`gas_history`, `water_history`,
  `electricity_history`) renvoient une page :
  `{ items, total, page, per_page }` (#257). `page` est celle réellement servie :
  une page au-delà du dernier relevé est ramenée à la dernière page non vide.
  Paramètres communs : `page` (défaut 1, minimum 1) et `per_page` (défaut 25,
  plafonné à 200). Un `per_page` inutilisable (absent, vide, non numérique, ≤ 0)
  retombe sur le défaut ; `per_page` est toujours répété dans la réponse, qui
  fait foi.
- Les actions **« estimation de coût »** (`month_cost`, `cost_estimate`,
  `gas_cost`, `gas_month_cost`) renvoient un objet avec `available: bool` ; si
  `false`, un champ `reason` explique pourquoi (voir `CostCalculationService`).
- Les actions **« mutation »** (POST) renvoient `{ "ok": true, … }` en succès et
  `{ "ok": false, "error": … }` en erreur.

---

## Actions GET

| `action` | Paramètres | Réponse (forme) |
|----------|-----------|-----------------|
| `today` | — | Index du jour (`LegacyDailyRepository::getTodayIndexValues`). |
| `monthly_delta` | — | Deltas du mois courant (`getMonthlyDeltas`). |
| `chart_data` | `days` (défaut 30) | Séries journalières pour le graphique (`getDailyDeltasForChart`). |
| `month_cost` | `year` (défaut année courante), `month` (défaut mois courant) | `estimateMonthElectricity(year, month)`. `422` si `year ∉ [2000,2100]` ou `month ∉ [1,12]`. |
| `gas_history` | `page`, `per_page` | Page de relevés gaz (`UtilityReadingRepository::getReadingsPage`) : `{ items:[{ id, reading_at, counter_m3, delta_m3:number\|null }], total, page, per_page }`, du plus récent au plus ancien. `delta_m3` de la dernière ligne tient compte du relevé précédent, hors page. |
| `water_history` | `page`, `per_page` | Page de relevés eau, même forme que `gas_history`. |
| `electricity_history` | `page`, `per_page` | Page d'index élec (`ElectricityReadingRepository::getHistoryPage`) : `{ items:[{ reading_at, import_t1, import_t2, export_t1, export_t2, production }], previous, total, page, per_page }`. Une page = `per_page` horodatages distincts ; `previous` est le relevé immédiatement plus ancien (ou `null`), fourni pour le calcul des deltas côté client. |
| `electricity_monthly_series` | `months` (défaut 12, borné 1–60) | Consommation mensuelle pour le graphique (`getMonthlyDeltaSeries`) : `[{ month:"YYYY-MM", import_t1, import_t2, export_t1, export_t2, solar:number\|null, partial:bool }]`. |
| `gas_monthly_series` | `months` (défaut 12, borné 1–60) | Volume gaz mensuel (`UtilityConsumptionSeriesService`) : `[{ month:"YYYY-MM", delta_m3, partial:bool }]`. |
| `water_monthly_series` | `months` (défaut 12, borné 1–60) | Volume eau mensuel, même forme que `gas_monthly_series`. |
| `cost_estimate` | — | `estimateCurrentMonthElectricity()`. |
| `gas_cost` | — | `estimateLastGasPeriod()`. |
| `gas_month_cost` | `year`, `month` (mêmes défauts/validations que `month_cost`) | `estimateMonthGas(year, month)`. |
| `water_month_cost` | `year`, `month` (mêmes défauts/validations que `month_cost`) | `estimateMonthWater(year, month)` — **volume m³ uniquement** (pas de coût : aucun tarif eau). |
| `tariffs` | — | `{ electricity: [...], gas: [...] }` ; chaque grille : `{ id, name, valid_from (Y-m-d), valid_to (Y-m-d|null, **exclue**), lines, pricing_mode }`. |
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
| `gas_entry` | `{ counter_m3: float>=0, reading_at?: date }` | `{ ok:true, saved_at (ISO), counter_m3 }` | `counter_m3` invalide/<0 ; date invalide ; relevé déjà existant à cette date ; `counter_m3` < relevé précédent ou > relevé suivant. |
| `water_entry` | `{ counter_m3: float>=0, reading_at?: date }` | `{ ok:true, saved_at (ISO), counter_m3 }` | idem `gas_entry`. |
| `save_tariff` | `{ energy_type: "electricity"\|"gas"\|"water", name, valid_from: date, valid_to?: date (**exclue**), lines: object, pricing_mode?: string }` | `{ ok:true, id }` | champ requis manquant (`energy_type`, `name`, `valid_from`, `lines`) ; `energy_type` hors énum ; `valid_from`/`valid_to` invalides ; `valid_to` ≤ `valid_from` (plage vide, la borne de fin étant exclue) ; clé de ligne hors format ; montant de ligne illisible ; aucune ligne exploitable. |
| *(autre)* | — | — | `400 { ok:false, error:"Unknown POST action" }`. |

Un champ `date` (`reading_at`, `valid_from`, `valid_to`) doit porter une **date
calendaire réelle** : `2026-07-31`, `2026-07-31 12:00:00`, ISO 8601 avec offset ou
`Z`, fuseau nommé, `@timestamp`. Sont refusées en 422 les dates impossibles
(`2026-02-31`, `2026-02-29` hors année bissextile), que le parseur décalerait
sinon en silence, et les valeurs sans date qui se résoudraient sur l'horloge du
serveur (`2026`, `12:00`, `now`, `tomorrow`).

**Bornes de fin exclues** (#1) : `valid_to` désigne le premier jour **non** couvert
par la grille — une grille valable sur juin 2026 s'écrit
`valid_from: "2026-06-01", valid_to: "2026-07-01"`. Deux grilles successives se
recollent sur la même date. Une plage vide (`valid_to` ≤ `valid_from`, jour
calendaire comparé) est refusée en 422 : la grille ne serait active aucun jour.
Cf. [`date-bounds.md`](date-bounds.md).

`reading_at` absent ⇒ horodatage `now`. Un index de 0 est accepté (compteur
neuf) ; seule une valeur négative est refusée. L'insertion rétroactive est
permise : le relevé est encadré par `getReadingBefore()` / `getReadingAfter()`,
et son index doit rester compris entre celui du relevé précédent et celui du
relevé suivant. Un relevé au même horodatage qu'un existant est refusé (422),
y compris en cas de course avec l'`INSERT` (contrainte `uq_utility_readings`).

`save_tariff` accepte `lines` au format plat `clé => montant`, le `component_kind`
étant déduit du catalogue (clé inconnue ⇒ `per_kwh`). Deux clés paramètrent la
formule dynamique (#228) au lieu d'être facturées : `spot_coefficient`
(multiplicateur du prix spot) et `spot_offset` (€/kWh TTC). Elles sont typées comme
telles **quel que soit `energy_type`** : sur une grille gaz ou eau elles restent
inertes, alors que le repli `per_kwh` facturerait un coefficient 1,08 comme
1,08 €/kWh. Elles apparaissent en retour dans `lines` des grilles et dans le
`tariff_rates` des réponses de coût — clés additionnelles, non cassantes.

Les **clés** de `lines` suivent le même format que le formulaire web (#265) :
`^[a-z][a-z0-9_]{0,99}$` (minuscule initiale, puis minuscules, chiffres et
underscores, 100 caractères au plus). Toute autre clé est refusée en 422
`Invalid tariff line key: <clé>`, sans rien persister — ce qui inclut un `lines`
envoyé comme **liste JSON** (`[0.1, 0.2]`), dont les clés entières `0`, `1`
étaient jusqu'ici enregistrées telles quelles. Une clé hors format n'est reconnue
par aucun `component_kind` du catalogue : elle retombait sur `per_kwh` (`"Energy
T1"` facturé comme une taxe €/kWh au lieu de `energy_t1`) et rendait ensuite la
grille non réenregistrable depuis le formulaire web, qui refuse ces clés. La clé
est validée **avant** le montant : elle est refusée même si la ligne porte un
montant vide. Elle n'est ni trimée ni mise en minuscules au passage —
contrairement à la saisie du formulaire, une clé d'intégration mal formée est
signalée, pas rattrapée : `"Energy_T1"` et `"energy_t1\n"` sont refusés.

Les montants de `lines` suivent la même règle que le formulaire web (#262) : un
montant `null`, vide ou blanc vaut **ligne non renseignée** et est sauté sans
erreur ; un montant renseigné mais non numérique (`"0,08"`, `"0.08 €"`, typo) est
refusé en 422 `Invalid amount for tariff line: <clé>`, sans rien persister. Il
était auparavant sauté en silence : la grille partait amputée de cette ligne, en
200, et faussait les coûts sans le moindre signal. Une fois les lignes blanches
écartées, un `lines` sans aucune ligne exploitable est refusé en 422
`At least one valid tariff line is required`.

Contrairement à la saisie web, l'API ne rejette pas un coefficient hors bornes
(`]0 ; 5]`) : il est neutralisé à 1,0 au calcul (`SpotFormulaResolver`) et signalé
par `dynamic.formula.coefficient_rejected` dans les réponses de coût.

`pricing_mode` (#245) porte le mode du contrat de la grille : `fixed` (défaut,
comportement des intégrations existantes), `dynamic_hourly` ou `dynamic_quarter`.
Non validé côté API, comme le reste : une valeur hors énum retombe sur `fixed`, et
le champ est forcé à `fixed` hors électricité. Il apparaît en retour dans `tariffs`.

### Champs de mode dans les réponses de coût (#245)

`cost_estimate` et `month_cost` exposent, à la racine comme dans `dynamic` :

| Champ | Sens |
|-------|------|
| `pricing_mode` | Mode de la sous-période dynamique dominante, `fixed` si aucune. |
| `pricing_modes` | `[{from, to, days, mode}]` — mode **effectivement appliqué** à chaque sous-période. |
| `is_mixed` | La période mêle-t-elle plusieurs modes (bascule de contrat) ? |
| `dynamic_days` | Nombre de jours réellement facturés au prix de marché. |
| `dynamic_unavailable_reason` | Présent seulement si des sous-périodes dynamiques ont dû retomber sur le tarif fournisseur faute de prix. |
| `dynamic.is_simulation` | `true` quand la projection tout-dynamique ne correspond à aucun contrat en vigueur sur la période. |

Clés additionnelles, non cassantes. Les sous-périodes sont rendues dans l'ordre
chronologique, et `dynamic_days` vaut toujours 0 quand `dynamic_prices.enabled` est
faux côté serveur.

---

## Couverture de tests (Phase 0)

La logique métier derrière les actions de coût est couverte par
`tests/Unit/Service/CostCalculationServiceTest.php` (via fakes des repositories)
et `tests/Unit/Service/TariffCalculatorServiceTest.php` (calculs purs).
Les tests **HTTP de bout en bout** de `api.php` (serveur + base) sont **reportés
en Phase 3**, après extraction des contrôleurs.
