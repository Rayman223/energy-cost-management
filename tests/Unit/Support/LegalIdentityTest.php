<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LegalIdentity;
use PHPUnit\Framework\TestCase;

final class LegalIdentityTest extends TestCase
{
    public function testReturnsAllConfiguredFields(): void
    {
        $identity = LegalIdentity::from(['legal' => [
            'publisher'     => 'Exemple SRL',
            'address'       => 'Rue de la Loi 1, 1000 Bruxelles',
            'contact_email' => 'rgpd@exemple.be',
            'host'          => 'Hébergeur SA (Belgique)',
            'jurisdiction'  => 'Belgique',
        ]]);

        self::assertSame('Exemple SRL', $identity['publisher']);
        self::assertSame('Rue de la Loi 1, 1000 Bruxelles', $identity['address']);
        self::assertSame('rgpd@exemple.be', $identity['contact_email']);
        self::assertSame('Hébergeur SA (Belgique)', $identity['host']);
        self::assertSame('Belgique', $identity['jurisdiction']);
    }

    /**
     * Le template affiche « information non configurée » sur un champ null : la
     * normalisation doit donc renvoyer toutes les clés, même vides.
     */
    public function testAlwaysReturnsEveryFieldEvenWhenSectionIsMissing(): void
    {
        foreach ([[], ['legal' => []], ['legal' => 'oops']] as $config) {
            $identity = LegalIdentity::from($config);

            self::assertSame(LegalIdentity::FIELDS, array_keys($identity));
            self::assertSame([null, null, null, null, null], array_values($identity));
        }
    }

    public function testTrimsValuesAndTreatsBlanksAsMissing(): void
    {
        $identity = LegalIdentity::from(['legal' => [
            'publisher' => "  Exemple SRL \n",
            'address'   => '   ',
            'host'      => '',
        ]]);

        self::assertSame('Exemple SRL', $identity['publisher']);
        self::assertNull($identity['address']);
        self::assertNull($identity['host']);
    }

    /**
     * L'adresse part dans un `mailto:` : une valeur non conforme est écartée.
     */
    public function testRejectsInvalidContactEmail(): void
    {
        self::assertNull(LegalIdentity::from(['legal' => ['contact_email' => 'pas-une-adresse']])['contact_email']);
        self::assertNull(LegalIdentity::from(['legal' => ['contact_email' => 'a@b']])['contact_email']);
        self::assertNull(LegalIdentity::from(['legal' => ['contact_email' => 42]])['contact_email']);
    }

    public function testIgnoresNonStringValues(): void
    {
        $identity = LegalIdentity::from(['legal' => [
            'publisher'    => ['Exemple SRL'],
            'jurisdiction' => 42,
        ]]);

        self::assertNull($identity['publisher']);
        self::assertNull($identity['jurisdiction']);
    }
}
