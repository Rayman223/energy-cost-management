<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ComponentKind;
use App\Domain\TariffUnitRate;
use PHPUnit\Framework\TestCase;

/**
 * Le tarif indicatif au kWh publié par /stats (#8) est la seule valeur comparée
 * entre un foyer et la moyenne de son pays. Deux erreurs y seraient invisibles à
 * l'œil et pourtant grossières : sommer un `spot_coefficient` (un multiplicateur
 * 1,08 deviendrait 1,08 €/kWh), ou compter T1 et T2 à poids plein (le prix
 * doublerait pour toute grille bihoraire).
 *
 * Ce test verrouille les poids un par un, et surtout l'EXHAUSTIVITÉ : un case
 * ajouté à ComponentKind sans poids déclaré doit faire rougir la CI, pas valoir
 * zéro en silence — sans quoi une nouvelle composante €/kWh serait tout
 * simplement absente du prix publié.
 */
final class TariffUnitRateTest extends TestCase
{
    public function testEveryComponentKindDeclaresAWeight(): void
    {
        foreach (ComponentKind::cases() as $kind) {
            self::assertTrue(
                TariffUnitRate::hasWeight($kind),
                sprintf(
                    'ComponentKind::%s n\'a pas de poids déclaré dans TariffUnitRate. '
                    . 'Décidez s\'il compte dans le tarif au kWh (et avec quel poids) '
                    . 'plutôt que de le laisser valoir zéro en silence.',
                    $kind->name,
                ),
            );
        }
    }

    public function testFullWeightKindsCountEntirely(): void
    {
        self::assertSame(1.0, TariffUnitRate::weight(ComponentKind::EnergyFlat));
        self::assertSame(1.0, TariffUnitRate::weight(ComponentKind::PerKwh));
    }

    public function testDayAndNightSharesSumToOne(): void
    {
        $energy = TariffUnitRate::weight(ComponentKind::EnergyT1)
            + TariffUnitRate::weight(ComponentKind::EnergyT2);
        $network = TariffUnitRate::weight(ComponentKind::PerKwhT1)
            + TariffUnitRate::weight(ComponentKind::PerKwhT2);

        self::assertSame(1.0, $energy, 'T1+T2 doit valoir un volume plein, pas deux.');
        self::assertSame(1.0, $network);
    }

    public function testFixedInjectionAndSpotKindsAreExcluded(): void
    {
        $excluded = [
            ComponentKind::FixedMonthly,
            ComponentKind::FixedAnnual,
            ComponentKind::InjectionT1,
            ComponentKind::InjectionT2,
            ComponentKind::SpotCoefficient,
            ComponentKind::SpotOffset,
            ComponentKind::PerM3,
        ];

        foreach ($excluded as $kind) {
            self::assertSame(
                0.0,
                TariffUnitRate::weight($kind),
                sprintf('%s ne doit pas peser dans un €/kWh.', $kind->value),
            );
        }
    }

    public function testRateSumsOnlyPerKwhComponents(): void
    {
        // 0,20 (énergie jour) + 0,10 (énergie nuit) au mix 50/50 = 0,15
        // + 0,05 (transport, poids plein) = 0,20. L'abonnement, le crédit
        // d'injection et le coefficient spot n'entrent pas.
        $rate = TariffUnitRate::fromCalculationTariff($this->tariff([
            ['kind' => 'energy_t1',        'amount' => 0.20],
            ['kind' => 'energy_t2',        'amount' => 0.10],
            ['kind' => 'per_kwh',          'amount' => 0.05],
            ['kind' => 'fixed_monthly',    'amount' => 12.0],
            ['kind' => 'injection_t1',     'amount' => 0.04],
            ['kind' => 'spot_coefficient', 'amount' => 1.08],
        ]));

        self::assertNotNull($rate);
        self::assertEqualsWithDelta(0.20, $rate, 1e-9);
    }

    public function testGridWithoutAnyPerKwhLineHasNoRate(): void
    {
        // Grille dynamique typique : la part énergie vient du marché, seules
        // subsistent des lignes qui ne font pas un prix du kWh publiable.
        $rate = TariffUnitRate::fromCalculationTariff($this->tariff([
            ['kind' => 'fixed_monthly',    'amount' => 12.0],
            ['kind' => 'spot_coefficient', 'amount' => 1.08],
            ['kind' => 'spot_offset',      'amount' => 0.03],
        ]));

        self::assertNull($rate, 'Publier « réseau et taxes seuls » comme prix du kWh serait trompeur.');
    }

    public function testUnknownKindFallsBackToPerKwh(): void
    {
        // fromStringOrDefault() replie un kind inconnu sur per_kwh : une grille
        // importée d'une version antérieure reste comptée plutôt qu'ignorée.
        $rate = TariffUnitRate::fromCalculationTariff($this->tariff([
            ['kind' => 'kind_inexistant', 'amount' => 0.07],
        ]));

        self::assertNotNull($rate);
        self::assertEqualsWithDelta(0.07, $rate, 1e-9);
    }

    public function testHtvaIsDerivedFromTheGridOwnVatRate(): void
    {
        self::assertEqualsWithDelta(0.10, TariffUnitRate::htvaFromTtc(0.121, 21.0), 1e-9);
        // Taux nul : le TTC est déjà le HTVA, pas de division parasite.
        self::assertEqualsWithDelta(0.10, TariffUnitRate::htvaFromTtc(0.10, 0.0), 1e-9);
    }

    /**
     * @param list<array{kind: string, amount: float}> $lines
     * @return array{vat_rate: float, currency: string, lines: list<array{key: string, kind: string, amount: float, label: string|null, category: string}>}
     */
    private function tariff(array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = [
                'key'      => 'line_' . $i,
                'kind'     => $line['kind'],
                'amount'   => $line['amount'],
                'label'    => null,
                'category' => 'energy',
            ];
        }

        return ['vat_rate' => 21.0, 'currency' => 'EUR', 'lines' => $out];
    }
}
