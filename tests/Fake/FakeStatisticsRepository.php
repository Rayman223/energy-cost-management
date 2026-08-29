<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\StatisticsRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository d'agrégats : sert des tableaux en mémoire, sans base.
 *
 * Compte les appels ({@see $calls}) : la mémoïsation de StatisticsService est un
 * comportement testé, pas un détail — la page interroge l'agrégat une fois pour le
 * bloc public et le relit pour le bloc privé.
 *
 * Les données servies ici sont supposées DÉJÀ k-anonymisées : le seuil est
 * appliqué en SQL, c'est StatisticsRepositoryDbTest qui le vérifie. Ce fake sert
 * à tester ce que le service fait ensuite (choix de devise, arrondis, écarts).
 */
final class FakeStatisticsRepository implements StatisticsRepositoryInterface
{
    /** @var array<string, int> Nombre d'appels par méthode. */
    public array $calls = [];

    /** @var list<array{bucket: string, currency: string, households: int, ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float}> */
    public array $rates = [];

    /** @var list<array{bucket: string, currency: string, category: string, households: int, ttc_per_kwh: float}> */
    public array $categories = [];

    /** @var list<array{bucket: string, households: int, value: float}> */
    public array $electricity = [];

    /** @var array<string, list<array{bucket: string, households: int, value: float}>> */
    public array $utility = [];

    /** @var list<array{bucket: string, fixed: int, dynamic: int}> */
    public array $mix = [];

    /** @var list<array{zone: string, resolution_min: int, avg_price: float, points: int, first_at: string, last_at: string}> */
    public array $spot = [];

    /** @var array{households: int, countries: int} */
    public array $coverage = ['households' => 0, 'countries' => 0];

    /** @var array<string, float> */
    public array $householdSeries = [];

    /** @var array<string, float> */
    public array $countrySeries = [];

    /** @var array{below: int, total: int}|null */
    public ?array $usageRank = null;

    /** @var array{below: int, total: int}|null */
    public ?array $rateRank = null;

    public function unitRateByCountry(): array
    {
        $this->record(__FUNCTION__);

        return $this->rates;
    }

    public function unitRateByCountryAndCategory(): array
    {
        $this->record(__FUNCTION__);

        return $this->categories;
    }

    public function electricityUsageByCountry(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->record(__FUNCTION__);

        return $this->electricity;
    }

    public function utilityUsageByCountry(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->record(__FUNCTION__);

        return $this->utility[$energyType] ?? [];
    }

    public function pricingModeByCountry(): array
    {
        $this->record(__FUNCTION__);

        return $this->mix;
    }

    public function spotPriceByZone(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->record(__FUNCTION__);

        return $this->spot;
    }

    public function coverage(): array
    {
        $this->record(__FUNCTION__);

        return $this->coverage;
    }

    public function householdMonthlySeries(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->record(__FUNCTION__);

        return $this->householdSeries;
    }

    public function countryMonthlySeries(string $country, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->record(__FUNCTION__);

        return $this->countrySeries;
    }

    public function usageRankInCountry(string $country, float $value, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $this->record(__FUNCTION__);

        return $this->usageRank;
    }

    public function rateRankInCountry(string $country, float $rate): ?array
    {
        $this->record(__FUNCTION__);

        return $this->rateRank;
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
