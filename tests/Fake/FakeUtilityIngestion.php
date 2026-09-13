<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\UtilityIngestionInterface;
use DateTimeImmutable;

final class FakeUtilityIngestion implements UtilityIngestionInterface
{
    /** @var array<string, float> reading_at => counter_m3 */
    public array $saved = [];

    /**
     * Le faux ne modélise qu'un compteur : il se rend lui-même. Les tests qui
     * portent sur le CHOIX du compteur passent par la base (#55).
     */
    public function forMeter(?int $meterId): self
    {
        return $this;
    }

    public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3, bool $replace = false): bool
    {
        $key = $readingAt->format('Y-m-d H:i:s');
        if (isset($this->saved[$key]) && !$replace) {
            return false; // doublon ignoré (simule INSERT IGNORE)
        }

        // Mode replace : une valeur inchangée renvoie false (aucune ligne
        // modifiée), cohérent avec ON DUPLICATE KEY UPDATE côté MySQL.
        if (isset($this->saved[$key]) && $this->saved[$key] === $counterM3) {
            return false;
        }

        $this->saved[$key] = $counterM3;

        return true;
    }
}
