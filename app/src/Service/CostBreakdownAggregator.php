<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Agrège les décomptes de coût de plusieurs sous-périodes tarifaires (#196) en
 * un seul décompte, de la même forme que celui produit par TariffCalculatorService.
 * Le dashboard consomme donc le résultat sans changer de structure.
 *
 * Règles :
 *  - les lignes sont fusionnées par (clé, kind) : quantités et montants sommés,
 *    le taux affiché devient le taux EFFECTIF de la période (montant / quantité) ;
 *  - `htva` est la somme des HTVA de chaque sous-période, calculée avec le taux de
 *    TVA de SA grille — deux grilles peuvent avoir des taux différents ;
 *  - `vat_rate` n'est renseigné que si toutes les grilles partagent le même taux,
 *    sinon null (le dashboard affiche alors « dont TVA incluse » sans taux) ;
 *  - un seul décompte en entrée → renvoyé tel quel (cas mono-grille inchangé).
 */
final class CostBreakdownAggregator
{
    /**
     * @param  list<array<string, mixed>> $breakdowns Décomptes par sous-période, dans l'ordre chronologique.
     * @return array<string, mixed>
     */
    public function aggregate(array $breakdowns): array
    {
        if ($breakdowns === []) {
            return ['lines' => [], 'total' => 0.0, 'htva' => 0.0, 'vat_included' => 0.0, 'vat_rate' => null, 'energy_total' => 0.0];
        }

        if (count($breakdowns) === 1) {
            return $breakdowns[0];
        }

        $result = $this->aggregateLines($breakdowns);

        $total       = 0.0;
        $htva        = 0.0;
        $energyTotal = 0.0;
        /** @var list<float> $vatRates */
        $vatRates = [];

        foreach ($breakdowns as $b) {
            $total       += (float) ($b['total'] ?? 0.0);
            $htva        += (float) ($b['htva'] ?? 0.0);
            $energyTotal += (float) ($b['energy_total'] ?? 0.0);
            $vatRates[]   = (float) ($b['vat_rate'] ?? 21.0);
        }

        $uniqueRates = array_values(array_unique($vatRates));

        $result['total']        = round($total, 2);
        $result['htva']         = round($htva, 2);
        $result['vat_included'] = round($total - $htva, 2);
        $result['vat_rate']     = count($uniqueRates) === 1 ? $uniqueRates[0] : null;
        $result['energy_total'] = round($energyTotal, 2);

        if (isset($breakdowns[0]['mode'])) {
            $result = ['mode' => $breakdowns[0]['mode']] + $result;
        }

        foreach (['kwh', 'm3'] as $quantityKey) {
            if (isset($breakdowns[0][$quantityKey])) {
                $result[$quantityKey] = round($this->sumKey($breakdowns, $quantityKey), 3);
            }
        }

        return $result + $this->aggregateSolar($breakdowns);
    }

    /**
     * Fusionne les lignes par (clé, kind), dans leur ordre d'apparition.
     *
     * @param  list<array<string, mixed>> $breakdowns
     * @return array<string, mixed>
     */
    private function aggregateLines(array $breakdowns): array
    {
        /** @var array<string, array<string, mixed>> $merged */
        $merged = [];

        /** @var array<string, float> $rateWeighted Σ (taux × quantité) par ligne fusionnée. */
        $rateWeighted = [];

        foreach ($breakdowns as $b) {
            /** @var list<array<string, mixed>> $lines */
            $lines = $b['lines'] ?? [];
            foreach ($lines as $line) {
                $id       = (string) $line['key'] . '|' . (string) $line['kind'];
                $quantity = (float) $line['quantity'];

                $rateWeighted[$id] = ($rateWeighted[$id] ?? 0.0) + (float) ($line['rate'] ?? 0.0) * $quantity;

                if (!isset($merged[$id])) {
                    $merged[$id] = $line;
                    continue;
                }

                $merged[$id]['quantity'] = (float) $merged[$id]['quantity'] + $quantity;
                $merged[$id]['amount']   = (float) $merged[$id]['amount']   + (float) $line['amount'];
            }
        }

        $lines = [];
        foreach ($merged as $id => $line) {
            $quantity = (float) $line['quantity'];
            $amount   = (float) $line['amount'];

            // Taux effectif de la période : moyenne des taux des sous-périodes,
            // pondérée par leurs quantités. On ne dérive PAS le taux de
            // amount / quantity : la formule diffère selon le kind (fixed_annual
            // est un €/an réparti sur des jours, injection_* est un crédit dont le
            // montant est négatif) et le taux affiché doit rester dans l'unité de
            // la grille. À taux identiques sur toutes les sous-périodes, la moyenne
            // pondérée redonne exactement ce taux.
            if ($quantity !== 0.0) {
                $line['rate'] = round($rateWeighted[$id] / $quantity, 7);
            }

            $line['quantity'] = round($quantity, 4);
            $line['amount']   = round($amount, 4);
            $lines[]          = $line;
        }

        return ['lines' => $lines];
    }

    /**
     * Bloc informatif solaire : quantités sommées, taux d'économie effectif.
     * Renvoie les clés à null quand aucune sous-période n'a de production PV.
     *
     * @param  list<array<string, mixed>> $breakdowns
     * @return array<string, float|null>
     */
    private function aggregateSolar(array $breakdowns): array
    {
        if (!array_key_exists('import_kwh', $breakdowns[0])) {
            return []; // gaz / eau : pas de bloc solaire
        }

        $produced = $this->sumKey($breakdowns, 'solar_produced');
        $consumed = $this->sumKey($breakdowns, 'solar_consumed');
        $savings  = $this->sumKey($breakdowns, 'solar_savings');
        $hasSolar = $produced > 0.0;

        return [
            'import_kwh'           => round($this->sumKey($breakdowns, 'import_kwh'), 3),
            'export_kwh'           => round($this->sumKey($breakdowns, 'export_kwh'), 3),
            'solar_produced'       => $hasSolar ? round($produced, 3) : null,
            'solar_consumed'       => $hasSolar ? round($consumed, 3) : null,
            'self_consumption_pct' => $hasSolar ? round($consumed / $produced * 100.0, 1) : null,
            'solar_savings_rate'   => $hasSolar && $consumed > 0.0 ? round($savings / $consumed, 6) : null,
            'solar_savings'        => $hasSolar ? round($savings, 2) : null,
        ];
    }

    /**
     * Somme d'une clé numérique sur tous les décomptes (valeurs null ignorées).
     *
     * @param list<array<string, mixed>> $breakdowns
     */
    private function sumKey(array $breakdowns, string $key): float
    {
        $sum = 0.0;
        foreach ($breakdowns as $b) {
            $sum += (float) ($b[$key] ?? 0.0);
        }

        return $sum;
    }
}
