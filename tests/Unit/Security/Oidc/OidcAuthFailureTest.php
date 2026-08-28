<?php

declare(strict_types=1);

namespace Tests\Unit\Security\Oidc;

use App\Security\Oidc\OidcAuthFailure;
use PHPUnit\Framework\TestCase;

/**
 * Ligne de journal d'un échec de connexion OIDC. Elle doit porter le diagnostic
 * réel (message de l'IdP), rester sur une seule ligne, et ne jamais devenir le
 * canal de fuite du code d'autorisation ou d'un secret.
 */
final class OidcAuthFailureTest extends TestCase
{
    /**
     * L'exception jumbojett n'est volontairement pas instanciée ici : charger sa
     * classe déclencherait les dépréciations PHP 8.4 de la lib et polluerait la
     * suite. La classe réelle est reportée telle quelle par `$e::class`.
     */
    public function testDescribeCarriesReferenceProviderStageAndCause(): void
    {
        $line = OidcAuthFailure::describe(
            'a1b2c3d4',
            new \RuntimeException('User did not authorize openid scope.'),
            'discord',
            true,
        );

        self::assertStringContainsString('[a1b2c3d4]', $line);
        self::assertStringContainsString('provider=discord', $line);
        self::assertStringContainsString('stage=callback', $line);
        self::assertStringContainsString(\RuntimeException::class, $line);
        self::assertStringContainsString('User did not authorize openid scope.', $line);
    }

    public function testInitiationStageAndUnknownProvider(): void
    {
        $line = OidcAuthFailure::describe('a1b2c3d4', new \RuntimeException('boom'), '', false);

        self::assertStringContainsString('stage=initiation', $line);
        self::assertStringContainsString('provider=(inconnu)', $line);
    }

    /**
     * Un message multiligne (trace d'IdP, corps de réponse) doit être aplati :
     * une entrée de log = une ligne, sinon le diagnostic est illisible.
     */
    public function testMessageIsFlattenedToASingleLine(): void
    {
        $line = OidcAuthFailure::describe(
            'a1b2c3d4',
            new \RuntimeException("erreur\n  sur\tplusieurs\r\nlignes"),
            'google',
            true,
        );

        self::assertStringNotContainsString("\n", $line);
        self::assertStringContainsString('erreur sur plusieurs lignes', $line);
    }

    public function testLongMessageIsTruncated(): void
    {
        $line = OidcAuthFailure::describe('a1b2c3d4', new \RuntimeException(str_repeat('x', 500)), 'google', true);

        self::assertStringContainsString('…', $line);
        self::assertLessThan(500, mb_strlen($line));
    }

    public function testEmptyMessageStillProducesAUsableLine(): void
    {
        $line = OidcAuthFailure::describe('a1b2c3d4', new \RuntimeException(''), 'google', true);

        self::assertStringContainsString('(sans message)', $line);
    }

    /**
     * La référence est aléatoire : deux échecs consécutifs ne doivent pas
     * partager le même identifiant, sinon elle ne désigne plus rien.
     */
    public function testReferenceIsRandomAndShort(): void
    {
        $first = OidcAuthFailure::reference();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first);
        self::assertNotSame($first, OidcAuthFailure::reference());
    }
}
