<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AdvanceBalance;
use App\Domain\AdvanceSchedule;
use App\Repository\Contract\AdvanceScheduleRepositoryInterface;
use DateTimeImmutable;

/**
 * Croise les acomptes prélevés avec le coût réellement consommé sur une période,
 * énergie par énergie, et en déduit le solde de régularisation (#241).
 *
 * Découpage : ce service ne fait QUE l'aiguillage — additionner les échéances des
 * barèmes (algèbre pure, dans {@see AdvanceSchedule}) et rejouer le calcul de coût
 * sur la période demandée ({@see CostCalculationService}). Il ne calcule ni prix
 * ni consommation.
 *
 * Une énergie sans relevés ou sans grille tarifaire est RESTITUÉE avec sa raison
 * plutôt que tue, et exclue du total : un solde amputé d'une énergie en silence
 * annoncerait un remboursement qui n'existe pas (même principe que les `SKIP_*`
 * de {@see BillReconciliationService}).
 */
final class AdvanceBalanceService
{
    public function __construct(
        private readonly AdvanceScheduleRepositoryInterface $scheduleRepo,
        private readonly CostCalculationService $costService,
        /**
         * Le tarif dynamique est-il actif pour cet utilisateur ? En dynamique, la
         * part énergie du coût classique reposerait sur le prix fixe de la grille,
         * qui n'est pas celui facturé — le bilan serait faux du plus gros poste.
         */
        private readonly bool $dynamicPricing = false,
    ) {
    }

    /**
     * Bilan complet de la période, toutes énergies confondues.
     *
     * Acomptes et coût portent sur la MÊME fenêtre `[from, to[`
     * ({@see AdvanceSchedule::dueDatesWithin()}) : comparer deux fenêtres
     * différentes ferait apparaître un excédent d'un acompte entier sur une période
     * d'un an glissant.
     *
     * @return array{
     *     from: DateTimeImmutable,
     *     to: DateTimeImmutable,
     *     balances: list<AdvanceBalance>,
     *     total_paid: float,
     *     total_cost: float,
     *     total_balance: float,
     *     currency: string|null,
     *     has_gaps: bool,
     *     has_partial_data: bool,
     *     has_partial_advances: bool,
     *     mixed_currency: bool
     * }
     */
    public function balanceFor(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $schedules = $this->scheduleRepo->listFor();

        $balances   = [];
        $totalPaid  = 0.0;
        $totalCost  = 0.0;
        $currency   = null;
        $hasGaps    = false;
        $hasPartial    = false;
        $hasPartialAdv = false;
        $mixed         = false;

        foreach (AdvanceSchedule::ENERGY_TYPES as $energyType) {
            $balance = $this->balanceForEnergy($energyType, $schedules, $from, $to);

            // Sans échéance sur la période, il n'y a pas de bilan d'acompte à faire.
            // Afficher quand même le coût le retrancherait de 0 € payé et annoncerait
            // une dette imaginaire : un utilisateur qui n'a saisi que son acompte
            // électricité verrait son gaz compté intégralement comme « reste à payer ».
            if ($balance->dueCount === 0) {
                continue;
            }

            $balances[] = $balance;

            if (!$balance->isComparable()) {
                $hasGaps = true;
                continue;
            }

            // Devises hétérogènes : additionner des montants de devises différentes
            // produirait un total qui n'a de sens dans aucune des deux. On le signale
            // plutôt que de convertir sur un taux qu'on n'a pas.
            if ($currency !== null && $balance->currency !== null && $balance->currency !== $currency) {
                $mixed = true;
            }

            $totalPaid += $balance->paid;
            $totalCost += (float) $balance->cost;
            $currency ??= $balance->currency;
            $hasPartial    = $hasPartial || $balance->partialData;
            $hasPartialAdv = $hasPartialAdv || $balance->partialAdvances;
        }

        return [
            'from'             => $from,
            'to'               => $to,
            'balances'         => $balances,
            'total_paid'       => round($totalPaid, 2),
            'total_cost'       => round($totalCost, 2),
            'total_balance'    => round($totalPaid - $totalCost, 2),
            'currency'         => $currency,
            'has_gaps'         => $hasGaps,
            'has_partial_data'     => $hasPartial,
            'has_partial_advances' => $hasPartialAdv,
            'mixed_currency'       => $mixed,
        ];
    }

    /**
     * @param list<AdvanceSchedule> $schedules Barèmes de TOUTES les énergies.
     */
    private function balanceForEnergy(
        string $energyType,
        array $schedules,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): AdvanceBalance {
        $paid     = 0.0;
        $dueCount = 0;

        foreach ($schedules as $schedule) {
            if ($schedule->energyType !== $energyType) {
                continue;
            }

            $dates     = $schedule->dueDatesWithin($from, $to);
            $dueCount += count($dates);
            $paid     += count($dates) * $schedule->amountMonthly;
        }

        // Barème couvrant seulement une partie de la fenêtre (contrat démarré en
        // cours de période, plage de validité trop courte) : les acomptes portent
        // sur sept mois, le coût sur douze, et la comparaison annonce une dette qui
        // n'existe pas. Le cas SANS aucune échéance est déjà écarté par l'appelant ;
        // celui-ci ne peut pas l'être — le bilan garde du sens — mais il doit être dit.
        $partialAdvances = $dueCount < self::monthsIn($from, $to);

        $estimate = $this->estimate($energyType, $from, $to);

        if (($estimate['available'] ?? false) !== true) {
            return new AdvanceBalance(
                energyType:  $energyType,
                paid:        round($paid, 2),
                dueCount:    $dueCount,
                unavailable: is_string($estimate['reason'] ?? null) ? $estimate['reason'] : null,
                partialAdvances: $partialAdvances,
            );
        }

        // `cost` manque quand aucune grille tarifaire ne couvre la période : l'eau
        // renvoie alors le volume seul (rétrocompat #180). Sans coût, rien à
        // comparer — on le traite comme une indisponibilité, pas comme 0 €.
        $cost = $estimate['cost']['total'] ?? null;
        if (!is_float($cost) && !is_int($cost)) {
            return new AdvanceBalance(
                energyType:  $energyType,
                paid:        round($paid, 2),
                dueCount:    $dueCount,
                unavailable: 'no_tariff',
                partialAdvances: $partialAdvances,
            );
        }

        return new AdvanceBalance(
            energyType:  $energyType,
            paid:        round($paid, 2),
            dueCount:    $dueCount,
            cost:        round((float) $cost, 2),
            currency:    is_string($estimate['currency'] ?? null) ? $estimate['currency'] : null,
            // Absence de la clé traitée comme « incomplet » : seules les estimations
            // de PÉRIODE la posent, et seules celles-ci sont censées arriver ici.
            partialData: ($estimate['coverage_complete'] ?? false) !== true,
            partialAdvances: $partialAdvances,
        );
    }

    /**
     * Nombre de mois entamés par la fenêtre `[from, to[`, soit le nombre de
     * prélèvements attendus d'un barème qui la couvrirait entièrement.
     */
    private static function monthsIn(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $last = $to->modify('-1 day');

        return max(0, ((int) $last->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $last->format('n') - (int) $from->format('n')) + 1);
    }

    /**
     * Estimation de coût de l'énergie sur la période.
     *
     * @return array<string, mixed>
     */
    private function estimate(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return match ($energyType) {
            'electricity' => $this->dynamicPricing
                ? $this->costService->estimatePeriodElectricityDynamic($from, $to)
                : $this->costService->estimatePeriodElectricity($from, $to),
            'gas'         => $this->costService->estimatePeriodGas($from, $to),
            'water'       => $this->costService->estimatePeriodWater($from, $to),
            default       => ['available' => false, 'reason' => 'unknown_energy'],
        };
    }
}
