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
     *     has_cost_without_advance: bool,
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
        $hasCostOnly   = false;
        $mixed         = false;

        foreach (AdvanceSchedule::ENERGY_TYPES as $energyType) {
            $balance = $this->balanceForEnergy($energyType, $schedules, $from, $to);

            // Ni acompte, ni coût : rien à dire, la ligne serait vide.
            if ($balance->dueCount === 0 && $balance->cost === null) {
                continue;
            }

            $balances[] = $balance;

            if (!$balance->isComparable()) {
                $hasGaps = true;
                continue;
            }

            // Énergie chiffrée mais sans acompte saisi : la ligne est AFFICHÉE — vous
            // avez bien des index et une grille, les taire donnerait l'impression
            // qu'ils ne sont pas lus — mais elle reste hors du solde. L'inclure
            // retrancherait son coût de 0 € payé et annoncerait une dette imaginaire.
            if ($balance->dueCount === 0) {
                $hasCostOnly = true;
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
            'has_cost_without_advance' => $hasCostOnly,
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
        $ofEnergy = [];

        foreach ($schedules as $schedule) {
            if ($schedule->energyType !== $energyType) {
                continue;
            }

            $ofEnergy[] = $schedule;
            $dates      = $schedule->dueDatesWithin($from, $to);
            $dueCount  += count($dates);
            $paid      += count($dates) * $schedule->amountMonthly;
        }

        // Barème ne couvrant qu'une partie de la fenêtre (contrat démarré en cours
        // de période, plage de validité trop courte) : les acomptes portent sur sept
        // mois, le coût sur douze, et la comparaison annonce une dette qui n'existe
        // pas. Le cas SANS aucune échéance est déjà écarté par l'appelant ; celui-ci
        // ne peut pas l'être — le bilan garde du sens — mais il doit être dit.
        //
        // Mesuré sur les PLAGES DE VALIDITÉ et non sur un comptage d'échéances : un
        // prélèvement ne tombe qu'une fois par mois, si bien qu'une fenêtre non
        // alignée sur les mois (la période par défaut « il y a un an → aujourd'hui »
        // en est une) traverse treize mois calendaires pour douze prélèvements. Y
        // lire une lacune ferait crier au loup à chaque visite.
        $partialAdvances = !self::advancesCoverWindow($ofEnergy, $from, $to);

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
     * Les plages de validité des barèmes recouvrent-elles TOUTE la fenêtre
     * `[from, to[`, sans trou ?
     *
     * Question d'intervalles, volontairement indépendante du jour de prélèvement :
     * c'est la présence d'un contrat sur chaque journée de la période qui rend la
     * comparaison avec le coût légitime, pas le nombre de débits tombés.
     *
     * Les barèmes successifs se recollent (le chevauchement est refusé à la
     * saisie) ; une plage ouverte (`validTo === null`) court jusqu'au bout.
     *
     * @param list<AdvanceSchedule> $schedules Barèmes de l'énergie considérée.
     */
    private static function advancesCoverWindow(array $schedules, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        if ($schedules === []) {
            return false;
        }

        usort(
            $schedules,
            static fn (AdvanceSchedule $a, AdvanceSchedule $b): int => $a->validFrom <=> $b->validFrom,
        );

        // Dernier jour réellement dans la fenêtre : la borne de fin est exclue.
        $lastDay = $to->setTime(0, 0, 0)->modify('-1 day');
        $cursor  = $from->setTime(0, 0, 0);

        foreach ($schedules as $schedule) {
            if ($schedule->validFrom->setTime(0, 0, 0) > $cursor) {
                return false; // trou avant ce barème
            }

            if ($schedule->validTo === null) {
                return true; // plage ouverte : couvre tout le reste
            }

            $end = $schedule->validTo->setTime(0, 0, 0);
            if ($end >= $lastDay) {
                return true;
            }

            if ($end >= $cursor) {
                $cursor = $end->modify('+1 day');
            }
        }

        return false;
    }

    /**
     * Estimation de coût de l'énergie sur la période.
     *
     * L'électricité n'a plus qu'un seul point d'entrée depuis #245 : le mode vit dans
     * la grille, donc `estimatePeriodElectricity()` facture chaque sous-période dans le
     * sien. Un bilan couvrant un changement de contrat additionne les deux régimes, là
     * où l'ancien aiguillage devait trancher pour toute la période — et se trompait
     * forcément sur la moitié.
     *
     * @return array<string, mixed>
     */
    private function estimate(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return match ($energyType) {
            'electricity' => $this->costService->estimatePeriodElectricity($from, $to),
            'gas'         => $this->costService->estimatePeriodGas($from, $to),
            'water'       => $this->costService->estimatePeriodWater($from, $to),
            default       => ['available' => false, 'reason' => 'unknown_energy'],
        };
    }
}
