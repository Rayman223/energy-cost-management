<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\DynamicPriceRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository de prix dynamiques : renvoie une map horaire en mémoire,
 * sans base de données.
 */
final class FakeDynamicPriceRepository implements DynamicPriceRepositoryInterface
{
    /** @param array<string, float> $pricesByHour Map 'Y-m-d H:00:00' => €/kWh HTVA. */
    public function __construct(
        public array $pricesByHour = [],
        public ?DateTimeImmutable $latest = null,
    ) {
    }

    public function getAveragePriceByHour(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->pricesByHour;
    }

    public function latestPeriodEnd(): ?DateTimeImmutable
    {
        return $this->latest;
    }
}
