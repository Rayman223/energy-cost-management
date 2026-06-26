<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Points de mesure électricité prêts à envoyer (résultat de ElectricityReadingMerger),
 * avec les bornes de dates utiles au log et à la mise à jour de l'état de synchro.
 */
final class ElectricityPoints
{
    /**
     * @param array<int, array<string, float|int>> $points
     */
    public function __construct(
        public readonly array $points,
        public readonly string $firstDate,
        public readonly string $lastDate,
        public readonly string $lastTimestamp,
    ) {
    }

    public static function empty(): self
    {
        return new self([], '', '', '');
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }
}
