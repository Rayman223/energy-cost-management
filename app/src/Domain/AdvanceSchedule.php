<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * Barème d'acompte mensuel : « 120 €/mois du 01/01/2026 au 31/12/2026, prélevés
 * le 5 » (#241).
 *
 * On modélise le barème plutôt que chaque prélèvement : un montant révisé une ou
 * deux fois par an tient en deux lignes, là où la saisie ligne à ligne en
 * demanderait vingt-quatre. Le total payé sur une période s'obtient en comptant
 * les ÉCHÉANCES tombées dans cette période — pas au prorata des jours — pour que
 * le montant annoncé corresponde à ce qui a réellement été débité sur le compte.
 *
 * Value object immuable, sans dépendance à la base.
 */
final class AdvanceSchedule
{
    /**
     * Plafond de saisie d'un acompte mensuel, dicté par la colonne `DECIMAL(12,4)`.
     * Même raisonnement que {@see EnergyBill::MAX_AMOUNT} : la borne vient du
     * stockage, pas d'une hypothèse sur les dépenses, et elle évite qu'un montant
     * démesuré ne soit refusé par un `SQLSTATE[22003]` anglais — ou tronqué en
     * silence sur un serveur sans `STRICT_TRANS_TABLES`.
     */
    public const MAX_AMOUNT = 99999999.9999;

    /** Énergies pouvant porter un acompte, dans l'ordre d'affichage. */
    public const ENERGY_TYPES = ['electricity', 'gas', 'water'];

    public function __construct(
        public readonly int $id,
        public readonly string $energyType,
        public readonly float $amountMonthly,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validTo = null,
        public readonly int $dueDay = 1,
        public readonly string $note = '',
    ) {
    }

    /**
     * Échéances de ce barème tombant dans `[$from, $to[` — l'intersection de la
     * période demandée et de la plage de validité du barème.
     *
     * La borne de fin est EXCLUE, comme celle de la fenêtre de coût
     * ({@see \App\Service\CostCalculationService}) : sur une période « aujourd'hui
     * moins un an → aujourd'hui », une fin incluse compterait treize prélèvements
     * face à douze mois de consommation, et le solde annoncerait un excédent d'un
     * acompte entier qui n'existe pas. Comparer deux fenêtres différentes est le
     * seul moyen sûr de se tromper ici.
     *
     * La borne de VALIDITÉ du barème, elle, reste incluse : `valid_to` désigne le
     * dernier jour couvert par le contrat, tel que l'utilisateur l'a saisi.
     *
     * Un `dueDay` au-delà de la fin du mois (31 en février) est ramené au dernier
     * jour du mois : le fournisseur prélève alors le 28 ou le 29, pas le 3 mars.
     *
     * @return list<DateTimeImmutable>
     */
    public function dueDatesWithin(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $start = $this->maxDate($from, $this->validFrom)->setTime(0, 0, 0);

        // Fin de fenêtre exclue → dernier jour éligible = la veille. La validité du
        // barème, elle, est inclusive, d'où le min() sur la veille seulement.
        $end = $to->setTime(0, 0, 0)->modify('-1 day');
        if ($this->validTo !== null) {
            $end = $this->minDate($end, $this->validTo->setTime(0, 0, 0));
        }

        if ($end < $start) {
            return [];
        }

        $dates = [];
        // On itère sur les mois du premier au dernier, en fixant le jour de
        // prélèvement dans chacun. Partir du 1er du mois évite le débordement de
        // `modify('+1 month')` sur un 31 (31 janvier + 1 mois = 3 mars).
        $cursor = $start->modify('first day of this month')->setTime(0, 0, 0);
        $limit  = $end->modify('first day of this month')->setTime(0, 0, 0);

        while ($cursor <= $limit) {
            $due = $this->dueDateIn($cursor);

            if ($due >= $start && $due <= $end) {
                $dates[] = $due;
            }

            $cursor = $cursor->modify('+1 month');
        }

        return $dates;
    }

    /**
     * Montant total prélevé sur [$from, $to] au titre de ce barème.
     */
    public function totalWithin(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        return count($this->dueDatesWithin($from, $to)) * $this->amountMonthly;
    }

    /**
     * Ce barème recouvre-t-il la plage de validité d'un autre ? Deux barèmes qui
     * se chevauchent pour une même énergie feraient compter deux prélèvements
     * pour un seul débit réel.
     *
     * Une plage ouverte (`validTo === null`) court indéfiniment.
     */
    public function overlaps(DateTimeImmutable $otherFrom, ?DateTimeImmutable $otherTo): bool
    {
        $thisEnd  = $this->validTo?->setTime(0, 0, 0);
        $otherEnd = $otherTo?->setTime(0, 0, 0);

        $thisStart  = $this->validFrom->setTime(0, 0, 0);
        $otherStart = $otherFrom->setTime(0, 0, 0);

        if ($thisEnd !== null && $otherStart > $thisEnd) {
            return false;
        }

        if ($otherEnd !== null && $thisStart > $otherEnd) {
            return false;
        }

        return true;
    }

    /** Jour de prélèvement du mois de $monthStart, clampé sur la fin du mois. */
    private function dueDateIn(DateTimeImmutable $monthStart): DateTimeImmutable
    {
        $lastDay = (int) $monthStart->format('t');
        $day     = min(max(1, $this->dueDay), $lastDay);

        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $day,
        )->setTime(0, 0, 0);
    }

    private function maxDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function minDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    /** Plage de validité lisible : '2026-01-01 → 2026-12-31' ou '2026-01-01 → …'. */
    public function validityLabel(): string
    {
        return $this->validFrom->format('Y-m-d') . ' → ' . ($this->validTo?->format('Y-m-d') ?? '…');
    }

    /**
     * Reconstruit un barème depuis une ligne de `energy_advances`.
     *
     * Les DATE reviennent en 'Y-m-d' : parsées en UTC, fuseau de stockage du
     * projet, pour que la comparaison avec les bornes de période porte sur le
     * même référentiel.
     *
     * @param array{id: int|string, energy_type: string, amount_monthly: string|float, valid_from: string, valid_to: ?string, due_day: int|string, note: string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (int) $row['id'],
            energyType:    $row['energy_type'],
            amountMonthly: (float) $row['amount_monthly'],
            validFrom:     new DateTimeImmutable($row['valid_from'] . ' 00:00:00', Dates::utc()),
            validTo:       $row['valid_to'] !== null
                ? new DateTimeImmutable($row['valid_to'] . ' 00:00:00', Dates::utc())
                : null,
            dueDay:        (int) $row['due_day'],
            note:          $row['note'],
        );
    }
}
