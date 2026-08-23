<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Résultat du découpage tarifaire d'une période : les segments à facturer ET le
 * diagnostic de couverture (#6).
 *
 * Les deux voyagent ensemble parce qu'ils naissent du même balayage jour par jour :
 * le découpage comble les jours sans grille en prolongeant la grille voisine (cf.
 * TariffPeriodSplitter), ce qui rend un montant complet pour une période qui ne
 * l'est pas. `missingDays` est ce que ce comblement a masqué — le dashboard s'en
 * sert pour dire à l'utilisateur qu'il lui manque une grille, sans lui retirer le
 * montant estimé.
 *
 * `missingFrom`/`missingTo` bornent les jours non couverts (premier et dernier,
 * même si le trou n'est pas d'un seul tenant) ; elles sont nulles quand la période
 * est entièrement couverte.
 */
final class TariffCoverage
{
    /**
     * @param list<TariffSegment> $segments    Segments à facturer ; [] si aucune grille n'est exploitable.
     * @param int $totalDays                   Jours de la période balayée.
     * @param int $missingDays                 Jours sans aucune grille active, avant comblement.
     */
    public function __construct(
        public readonly array $segments,
        public readonly int $totalDays,
        public readonly int $missingDays = 0,
        public readonly ?DateTimeImmutable $missingFrom = null,
        public readonly ?DateTimeImmutable $missingTo = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missingDays <= 0;
    }

    /**
     * Description du trou de couverture pour la réponse JSON, ou null si la période
     * est entièrement couverte — la clé est alors omise, et l'UI n'affiche rien.
     *
     * @return array{days: int, total_days: int, from: string, to: string}|null
     */
    public function toGapArray(): ?array
    {
        if ($this->isComplete() || $this->missingFrom === null || $this->missingTo === null) {
            return null;
        }

        return [
            'days'       => $this->missingDays,
            'total_days' => $this->totalDays,
            'from'       => $this->missingFrom->format('Y-m-d'),
            'to'         => $this->missingTo->format('Y-m-d'),
        ];
    }
}
