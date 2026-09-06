<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Limits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plafonds anti-abus lus dans `config.php` (#55).
 *
 * La propriété qui compte : **aucune valeur de configuration ne peut rendre
 * l'application inutilisable**. Un zéro, une chaîne vide, une faute de frappe —
 * tous retombent sur un plafond valide, parce qu'un refus de créer motivé par
 * une coquille dans un fichier serveur serait indéchiffrable côté utilisateur.
 */
final class LimitsTest extends TestCase
{
    public function testReadsTheConfiguredValue(): void
    {
        self::assertSame(12, Limits::metersPerEnergy(['limits' => ['meters_per_energy' => 12]]));
    }

    /** Un `config.php` sans section `limits` doit marcher : la clé est optionnelle. */
    public function testFallsBackToTheDefaultWhenAbsent(): void
    {
        self::assertSame(Limits::DEFAULT_METERS_PER_ENERGY, Limits::metersPerEnergy([]));
        self::assertSame(Limits::DEFAULT_METERS_PER_ENERGY, Limits::metersPerEnergy(['limits' => []]));
    }

    public function testDefaultIsFive(): void
    {
        self::assertSame(5, Limits::DEFAULT_METERS_PER_ENERGY);
    }

    /**
     * Un plafond à 0 (ou négatif) empêcherait de créer le moindre compteur, sans
     * le moindre message expliquant pourquoi. Ramené à 1.
     */
    public function testClampsBelowOne(): void
    {
        self::assertSame(1, Limits::metersPerEnergy(['limits' => ['meters_per_energy' => 0]]));
        self::assertSame(1, Limits::metersPerEnergy(['limits' => ['meters_per_energy' => -20]]));
    }

    /** Au-delà de 50, le coût des agrégations multi-compteurs cesse d'être négligeable. */
    public function testClampsAboveFifty(): void
    {
        self::assertSame(50, Limits::metersPerEnergy(['limits' => ['meters_per_energy' => 5000]]));
    }

    /**
     * Une valeur numérique en chaîne est acceptée : `config.php` est écrit à la
     * main, `'8'` y est une faute de frappe fréquente et sans conséquence.
     */
    public function testAcceptsANumericString(): void
    {
        self::assertSame(8, Limits::metersPerEnergy(['limits' => ['meters_per_energy' => '8']]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function garbageValues(): iterable
    {
        yield 'chaîne non numérique' => ['beaucoup'];
        yield 'chaîne vide'          => [''];
        yield 'null'                 => [null];
        yield 'flottant'             => [7.5];
        yield 'booléen'              => [true];
        yield 'tableau'              => [[5]];
    }

    #[DataProvider('garbageValues')]
    public function testFallsBackToTheDefaultOnAnUnusableValue(mixed $value): void
    {
        self::assertSame(
            Limits::DEFAULT_METERS_PER_ENERGY,
            Limits::metersPerEnergy(['limits' => ['meters_per_energy' => $value]]),
        );
    }

    /** `limits` mal formée (scalaire au lieu d'un tableau) ne doit pas faire d'erreur. */
    public function testSurvivesAMalformedSection(): void
    {
        self::assertSame(Limits::DEFAULT_METERS_PER_ENERGY, Limits::metersPerEnergy(['limits' => 'oui']));
    }

    /** La valeur du template livré doit être exploitable telle quelle. */
    public function testExampleConfigYieldsItsOwnValue(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../app/config/config.example.php';

        self::assertSame(Limits::DEFAULT_METERS_PER_ENERGY, Limits::metersPerEnergy($config));
    }
}
