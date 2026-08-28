<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ComponentKind;
use App\Domain\Currency;

/**
 * Calculateur de coût énergétique générique (électricité, gaz, eau).
 *
 * Chaque grille est une liste de lignes typées (component_kind) ; le moteur
 * itère sur les lignes et applique la formule du kind. Cela rend le calcul
 * indépendant des taxes d'un pays : ajouter une composante = ajouter une ligne.
 *
 * Modèle TVA : les montants sont saisis TTC. La décomposition HT/TVA utilise le
 * taux `vat_rate` de la grille (remplace l'ancienne constante 21 % en dur).
 *
 * Formules par kind (cf. App\Domain\ComponentKind) :
 *   energy_flat / energy_t1 / energy_t2  €/kWh × conso (part énergie fournisseur)
 *   per_kwh / per_kwh_t1 / per_kwh_t2     €/kWh × conso (réseau, taxes)
 *   per_m3                                €/m³ × m³ (eau)
 *   fixed_monthly                         €/mois × mois entiers
 *   fixed_annual                          €/an proratisé sur la période
 *   injection_t1 / injection_t2           −(€/kWh × injection) (crédits)
 *
 * Les lignes spot_coefficient / spot_offset sont ignorées ici quel que soit le mode :
 * elles paramètrent l'indexation du prix de marché (cf. App\Domain\SpotFormula) et ne
 * constituent pas un poste de coût.
 */
final class TariffCalculatorService
{
    private const DAYS_YEAR = 365.0;

    /** Kinds contribuant à l'économie d'auto-consommation solaire (part variable jour). */
    private const SOLAR_SAVINGS_KINDS = [
        ComponentKind::EnergyT1,
        ComponentKind::EnergyFlat,
        ComponentKind::PerKwhT1,
        ComponentKind::PerKwh,
    ];

    /**
     * Calcule le coût électricité complet pour une période (tarif classique).
     *
     * @param float $kwhSolar Production PV totale de la période (kWh), pour l'info
     *                        d'auto-consommation. 0.0 si pas de données solaires.
     * @param float|null $monthsOverride Nombre de mois facturés imposé (cf. {@see computeLines()}).
     * @param array{vat_rate: float, currency?: string, lines: list<array{key: string, kind: string, amount: float, label: string|null}>} $tariff
     * @return array<string, mixed>
     */
    public function calculateElectricityCost(
        float $kwhT1,
        float $kwhT2,
        float $kwhExportT1,
        float $kwhExportT2,
        int   $days,
        array $tariff,
        float $kwhSolar = 0.0,
        ?float $monthsOverride = null,
    ): array {
        $quantities = [
            'kwh_t1'        => $kwhT1,
            'kwh_t2'        => $kwhT2,
            'kwh_export_t1' => $kwhExportT1,
            'kwh_export_t2' => $kwhExportT2,
        ];

        $result = $this->computeLines($tariff, $quantities, $days, null, 'electricity', $monthsOverride);
        $solar  = $this->solarInfo($tariff, $kwhT1, $kwhT2, $kwhExportT1, $kwhExportT2, $kwhSolar);

        $breakdown = array_merge($result, $solar);
        $breakdown['net_cost_per_kwh'] = self::netCostPerKwh($breakdown);

        return $breakdown;
    }

    /**
     * Variante « tarif dynamique » : la part énergie fournisseur est remplacée par
     * un coût indexé au prix de marché horaire ($dynamicEnergyTtc, déjà TTC). Tous
     * les autres postes restent identiques au tarif classique.
     *
     * @param array{vat_rate: float, currency?: string, lines: list<array{key: string, kind: string, amount: float, label: string|null}>} $tariff
     * @return array<string, mixed>
     */
    public function calculateElectricityCostDynamic(
        float $kwhT1,
        float $kwhT2,
        float $kwhExportT1,
        float $kwhExportT2,
        int   $days,
        array $tariff,
        float $dynamicEnergyTtc,
        float $kwhSolar = 0.0,
        ?float $monthsOverride = null,
    ): array {
        $quantities = [
            'kwh_t1'        => $kwhT1,
            'kwh_t2'        => $kwhT2,
            'kwh_export_t1' => $kwhExportT1,
            'kwh_export_t2' => $kwhExportT2,
        ];

        $result = $this->computeLines($tariff, $quantities, $days, $dynamicEnergyTtc, 'electricity', $monthsOverride);
        $solar  = $this->solarInfo($tariff, $kwhT1, $kwhT2, $kwhExportT1, $kwhExportT2, $kwhSolar);

        $breakdown = array_merge(['mode' => 'dynamic'], $result, $solar);
        $breakdown['net_cost_per_kwh'] = self::netCostPerKwh($breakdown);

        return $breakdown;
    }

    /**
     * Coût réel TTC du kWh prélevé au réseau (#9) : le total facturé — abonnement,
     * termes fixes annuels, taxes et TVA compris — rapporté aux seuls kWh importés.
     *
     * Les crédits d'injection sont RETRANCHÉS du total avant division : ils rémunèrent
     * l'énergie réinjectée, pas celle consommée. Les laisser dans le numérateur ferait
     * baisser le prix affiché du kWh prélevé à mesure que le prosumer exporte, ce qui
     * ne dit plus ce que coûte un kWh. Le crédit reste visible dans les lignes de détail.
     *
     * Renvoie null sans prélèvement sur la période : il n'y a alors pas de prix unitaire
     * à afficher, seulement des postes fixes.
     *
     * @param array<string, mixed> $breakdown Décompte électricité ({@see calculateElectricityCost()})
     *                                        ou son agrégat ({@see CostBreakdownAggregator}).
     */
    public static function netCostPerKwh(array $breakdown): ?float
    {
        $importKwh = (float) ($breakdown['import_kwh'] ?? 0.0);
        if ($importKwh <= 0.0) {
            return null;
        }

        /** @var list<array{kind?: string, amount?: float}> $lines */
        $lines     = $breakdown['lines'] ?? [];
        $injection = 0.0;
        foreach ($lines as $line) {
            $kind = ComponentKind::tryFrom((string) ($line['kind'] ?? ''));
            if ($kind !== null && $kind->isInjection()) {
                $injection += (float) ($line['amount'] ?? 0.0);
            }
        }

        return round(((float) ($breakdown['total'] ?? 0.0) - $injection) / $importKwh, 6);
    }

    /**
     * Calcule le coût gaz pour une période.
     *
     * @param float $kwh consommation en kWh (après conversion m³ → kWh via PCS)
     * @param array{vat_rate: float, currency?: string, lines: list<array{key: string, kind: string, amount: float, label: string|null}>} $tariff
     * @return array<string, mixed>
     */
    public function calculateGasCost(float $kwh, int $days, array $tariff, ?float $monthsOverride = null): array
    {
        $result = $this->computeLines($tariff, ['kwh_t1' => $kwh], $days, null, 'electricity', $monthsOverride);
        $result['kwh'] = round($kwh, 3);

        return $result;
    }

    /**
     * Calcule le coût de l'eau pour une période (composantes en €/m³ + fixes).
     *
     * @param float $m3 volume consommé en m³
     * @param array{vat_rate: float, currency?: string, lines: list<array{key: string, kind: string, amount: float, label: string|null}>} $tariff
     * @return array<string, mixed>
     */
    public function calculateWaterCost(float $m3, int $days, array $tariff, ?float $monthsOverride = null): array
    {
        $result = $this->computeLines($tariff, ['m3' => $m3], $days, null, 'water', $monthsOverride);
        $result['m3'] = round($m3, 3);

        return $result;
    }

    /**
     * Moteur générique : applique la formule de chaque ligne selon son kind.
     *
     * @param array{vat_rate?: float, currency?: string, lines?: list<array{key: string, kind: string, amount: float, label: string|null, category?: string|null}>} $tariff
     * @param array{kwh_t1?: float, kwh_t2?: float, kwh_export_t1?: float, kwh_export_t2?: float, m3?: float} $quantities
     * @param float|null $dynamicEnergyTtc  Coût énergie dynamique (mode dynamique) ; les kinds énergie fournisseur sont alors ignorés.
     * @param float|null $monthsOverride    Nombre de mois facturés imposé (fractionnaire autorisé), au lieu du
     *        plancher entier {@see wholeMonths()}. Utilisé par la proration multi-grilles (#196) : les mois de la
     *        période ENTIÈRE sont répartis entre ses sous-périodes, sinon deux demi-mois factureraient deux
     *        abonnements mensuels au lieu d'un.
     * @return array<string, mixed>
     */
    private function computeLines(
        array $tariff,
        array $quantities,
        int $days,
        ?float $dynamicEnergyTtc = null,
        string $energyType = 'electricity',
        ?float $monthsOverride = null,
    ): array {
        $kwhT1    = (float) ($quantities['kwh_t1'] ?? 0.0);
        $kwhT2    = (float) ($quantities['kwh_t2'] ?? 0.0);
        $exportT1 = (float) ($quantities['kwh_export_t1'] ?? 0.0);
        $exportT2 = (float) ($quantities['kwh_export_t2'] ?? 0.0);
        $m3       = (float) ($quantities['m3'] ?? 0.0);
        $totalKwh = $kwhT1 + $kwhT2;

        $wholeMonths = $monthsOverride ?? (float) $this->wholeMonths($days);
        $isDynamic   = $dynamicEnergyTtc !== null;

        // Devise de la grille : elle donne le numérateur des unités affichées. Un
        // tarif sans devise explicite reste sur le défaut de la base.
        $currency = is_string($tariff['currency'] ?? null) && $tariff['currency'] !== ''
            ? (string) $tariff['currency']
            : Currency::DEFAULT;

        $lines       = [];
        $total       = 0.0;
        $energyTotal = 0.0;

        // En mode dynamique, la part énergie fournisseur est une ligne synthétique.
        if ($isDynamic) {
            $lines[] = [
                'key'      => 'energy_dynamic',
                'kind'     => ComponentKind::EnergyFlat->value,
                'group'    => 'energy',
                'label'    => null,
                'quantity' => round($totalKwh, 3),
                'unit'     => Currency::symbol($currency) . '/kWh',
                'rate'     => $totalKwh > 0.0 ? round($dynamicEnergyTtc / $totalKwh, 6) : null,
                'amount'   => round($dynamicEnergyTtc, 4),
            ];
            $total       += $dynamicEnergyTtc;
            $energyTotal += $dynamicEnergyTtc;
        }

        foreach ($tariff['lines'] ?? [] as $line) {
            $kind = ComponentKind::fromStringOrDefault((string) $line['kind']);
            $rate = (float) $line['amount'];

            // Paramètre de la formule d'indexation (#228), pas un poste facturé : à sauter
            // dans TOUS les modes. En dynamique il est déjà intégré au prix horaire par
            // SpotFormula ; en fixe il est inerte. Sans ce filtre, un coefficient 1,08
            // serait facturé comme 1,08 €/kWh.
            if ($kind->isSpotFormula()) {
                continue;
            }

            if ($isDynamic && $kind->isSupplierEnergy()) {
                continue; // remplacé par la ligne énergie dynamique
            }

            [$quantity, $amount] = $this->applyKind($kind, $rate, [
                'kwh_t1'    => $kwhT1,
                'kwh_t2'    => $kwhT2,
                'total_kwh' => $totalKwh,
                'export_t1' => $exportT1,
                'export_t2' => $exportT2,
                'm3'        => $m3,
                'months'    => $wholeMonths,
                'days'      => $days,
            ]);

            $lines[] = [
                'key'      => (string) $line['key'],
                'kind'     => $kind->value,
                'group'    => isset($line['category']) && $line['category'] !== ''
                    ? (string) $line['category']
                    : $kind->group(),
                'label'    => $line['label'] ?? null,
                'quantity' => round($quantity, 4),
                'unit'     => $kind->unit($energyType, $currency),
                'rate'     => $rate,
                'amount'   => round($amount, 4),
            ];

            $total += $amount;
            if ($kind->isSupplierEnergy()) {
                $energyTotal += $amount;
            }
        }

        $vatRate     = (float) ($tariff['vat_rate'] ?? 21.0);
        $htva        = $total / (1.0 + $vatRate / 100.0);
        $vatIncluded = $total - $htva;

        return [
            'lines'        => $lines,
            'total'        => round($total, 2),
            'htva'         => round($htva, 2),
            'vat_included' => round($vatIncluded, 2),
            'vat_rate'     => $vatRate,
            'energy_total' => round($energyTotal, 2),
        ];
    }

    /**
     * Quantité et montant (€) d'une ligne selon son kind.
     *
     * @param array{kwh_t1: float, kwh_t2: float, total_kwh: float, export_t1: float, export_t2: float, m3: float, months: float, days: int} $ctx
     * @return array{0: float, 1: float}  [quantité, montant]
     */
    private function applyKind(ComponentKind $kind, float $rate, array $ctx): array
    {
        return match ($kind) {
            ComponentKind::EnergyFlat, ComponentKind::PerKwh => [$ctx['total_kwh'], $ctx['total_kwh'] * $rate],
            ComponentKind::EnergyT1, ComponentKind::PerKwhT1 => [$ctx['kwh_t1'], $ctx['kwh_t1'] * $rate],
            ComponentKind::EnergyT2, ComponentKind::PerKwhT2 => [$ctx['kwh_t2'], $ctx['kwh_t2'] * $rate],
            ComponentKind::PerM3        => [$ctx['m3'], $ctx['m3'] * $rate],
            ComponentKind::FixedMonthly => [$ctx['months'], $ctx['months'] * $rate],
            ComponentKind::FixedAnnual  => [(float) $ctx['days'], $this->prorateAnnual($rate, $ctx['days'])],
            ComponentKind::InjectionT1  => [$ctx['export_t1'], -($ctx['export_t1'] * $rate)],
            ComponentKind::InjectionT2  => [$ctx['export_t2'], -($ctx['export_t2'] * $rate)],
            // Paramètres de la formule dynamique (#228) : jamais un montant. computeLines()
            // les écarte en amont ; ce bras garde le match exhaustif et sert de second filet.
            ComponentKind::SpotCoefficient, ComponentKind::SpotOffset => [0.0, 0.0],
        };
    }

    /**
     * Bloc informatif d'auto-consommation solaire (exclu du total facturé).
     * La production PV a lieu en journée → tarifs variables T1 (jour).
     *
     * @param array{vat_rate?: float, currency?: string, lines?: list<array{key: string, kind: string, amount: float, label: string|null, category?: string|null}>} $tariff
     * @return array<string, float|null>
     */
    private function solarInfo(
        array $tariff,
        float $kwhT1,
        float $kwhT2,
        float $kwhExportT1,
        float $kwhExportT2,
        float $kwhSolar,
    ): array {
        $totalKwh    = $kwhT1 + $kwhT2;
        $totalExport = $kwhExportT1 + $kwhExportT2;

        $savingsRate  = $this->sumRatesForKinds($tariff, self::SOLAR_SAVINGS_KINDS);
        $solarConsumed = max(0.0, $kwhSolar - $totalExport);
        $solarSavings  = $solarConsumed * $savingsRate;

        return [
            'import_kwh'           => round($totalKwh, 3),
            'export_kwh'           => round($totalExport, 3),
            'solar_produced'       => $kwhSolar > 0.0 ? round($kwhSolar, 3)      : null,
            'solar_consumed'       => $kwhSolar > 0.0 ? round($solarConsumed, 3) : null,
            'self_consumption_pct' => $kwhSolar > 0.0 ? round($solarConsumed / $kwhSolar * 100.0, 1) : null,
            'solar_savings_rate'   => $kwhSolar > 0.0 ? round($savingsRate, 6)   : null,
            'solar_savings'        => $kwhSolar > 0.0 ? round($solarSavings, 2)  : null,
        ];
    }

    /**
     * Somme des taux (€/kWh) des lignes appartenant à un ensemble de kinds.
     *
     * @param array{vat_rate?: float, currency?: string, lines?: list<array{key: string, kind: string, amount: float, label: string|null, category?: string|null}>} $tariff
     * @param list<ComponentKind> $kinds
     */
    private function sumRatesForKinds(array $tariff, array $kinds): float
    {
        $wanted = array_map(static fn (ComponentKind $k): string => $k->value, $kinds);
        $sum    = 0.0;
        foreach ($tariff['lines'] ?? [] as $line) {
            if (in_array((string) $line['kind'], $wanted, true)) {
                $sum += (float) $line['amount'];
            }
        }

        return $sum;
    }

    /**
     * Convertit des m³ de gaz en kWh via le coefficient PCS.
     * PCS moyen belge ≈ 10.55 kWh/m³ (référence Sibelga, configurable).
     */
    public function m3ToKwh(float $m3, float $pcsCoefficient = 10.55): float
    {
        return round($m3 * $pcsCoefficient, 3);
    }

    /**
     * Nombre de mois entiers facturés pour une période (plancher à 1).
     * L'abonnement est un forfait mensuel fixe, indépendant du nombre de jours.
     *
     * Public car la proration multi-grilles (#196) répartit les mois de la période
     * complète entre ses sous-périodes : la règle doit rester définie ici.
     */
    public function wholeMonths(int $days): int
    {
        return max(1, (int) round($days / 30.4375));
    }

    /**
     * Proratise un forfait annuel (€/an) sur le nombre de jours de la période.
     */
    private function prorateAnnual(float $annual, int $days): float
    {
        return $days * ($annual / self::DAYS_YEAR);
    }
}
