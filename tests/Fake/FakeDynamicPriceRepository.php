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
    /**
     * @param array<string, float> $pricesByHour       Moyenne horaire (repli) : map 'Y-m-d H:00:00' => €/kWh HTVA.
     * @param array<string, float> $hourlyPricesByHour  Prix horaire natif (PT60M) ; vide = pas de série native.
     * @param array<string, float> $quarterPricesBySlot Prix natif PT15M ; vide = pas de série 15 min.
     */
    public function __construct(
        public array $pricesByHour = [],
        public ?DateTimeImmutable $latest = null,
        public array $hourlyPricesByHour = [],
        public array $quarterPricesBySlot = [],
    ) {
    }

    public function getHourlyPrices(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->hourlyPricesByHour;
    }

    public function getQuarterPrices(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->quarterPricesBySlot;
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
