<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Adsense;
use PHPUnit\Framework\TestCase;

final class AdsenseTest extends TestCase
{
    public function testReturnsClientIdWhenEnabledAndWellFormed(): void
    {
        $config = ['adsense' => ['enabled' => true, 'client_id' => 'ca-pub-1234567890123456']];

        self::assertSame('ca-pub-1234567890123456', Adsense::clientId($config));
        self::assertTrue(Adsense::isEnabled($config));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(
            'ca-pub-1234567890123456',
            Adsense::clientId(['adsense' => ['enabled' => true, 'client_id' => "  ca-pub-1234567890123456\n"]]),
        );
    }

    public function testReturnsNullWhenSectionOrKeyIsMissing(): void
    {
        self::assertNull(Adsense::clientId([]));
        self::assertNull(Adsense::clientId(['adsense' => []]));
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => true]]));
    }

    /**
     * Un identifiant valide mais `enabled` non-vrai ne doit rien activer : c'est
     * le kill-switch qui coupe à la fois le script et l'élargissement de la CSP.
     */
    public function testReturnsNullWhenDisabled(): void
    {
        $clientId = 'ca-pub-1234567890123456';

        self::assertNull(Adsense::clientId(['adsense' => ['client_id' => $clientId]]));
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => false, 'client_id' => $clientId]]));
        // Comparaison stricte : une valeur « vraie » approchante ne suffit pas.
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => 1, 'client_id' => $clientId]]));
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => 'true', 'client_id' => $clientId]]));
    }

    /**
     * Cette valeur part dans l'URL du script tiers : tout ce qui n'est pas un
     * identifiant éditeur doit être écarté avant le rendu.
     */
    public function testReturnsNullForMalformedClientId(): void
    {
        foreach (
            [
                '',
                '   ',
                'pub-1234567890123456',                       // préfixe incomplet
                'ca-pub-',                                    // aucun chiffre
                'ca-pub-123',                                 // trop court
                'ca-pub-12345678901234567890123456789',       // trop long
                'ca-pub-1234567890123456"><script>alert(1)',  // injection
                'ca-pub-12345678901234a6',                    // caractère non numérique
            ] as $candidate
        ) {
            self::assertNull(
                Adsense::clientId(['adsense' => ['enabled' => true, 'client_id' => $candidate]]),
                sprintf('« %s » ne doit pas être accepté', $candidate),
            );
        }
    }

    public function testReturnsNullWhenConfigShapeIsUnexpected(): void
    {
        self::assertNull(Adsense::clientId(['adsense' => 'ca-pub-1234567890123456']));
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => true, 'client_id' => 42]]));
        self::assertNull(Adsense::clientId(['adsense' => ['enabled' => true, 'client_id' => ['x']]]));
    }

    public function testIsEnabledMirrorsClientId(): void
    {
        self::assertFalse(Adsense::isEnabled([]));
        self::assertFalse(Adsense::isEnabled(['adsense' => ['enabled' => true, 'client_id' => 'nope']]));
        self::assertTrue(Adsense::isEnabled(['adsense' => ['enabled' => true, 'client_id' => 'ca-pub-9876543210987654']]));
    }
}
