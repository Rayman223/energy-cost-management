<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Timezones;
use PHPUnit\Framework\TestCase;

final class TimezonesTest extends TestCase
{
    public function testOptionsAreNonEmptyWithIdAndLabel(): void
    {
        $options = Timezones::options();

        self::assertNotEmpty($options);
        foreach ($options as $o) {
            self::assertArrayHasKey('id', $o);
            self::assertArrayHasKey('label', $o);
            self::assertStringContainsString('UTC', $o['label']);
            self::assertStringEndsWith(' - ' . $o['id'], $o['label']);
        }
    }

    public function testKnownZoneIsPresentAndLabelled(): void
    {
        $byId = [];
        foreach (Timezones::options() as $o) {
            $byId[$o['id']] = $o['label'];
        }

        self::assertArrayHasKey('Europe/Brussels', $byId);
        self::assertStringContainsString(' - Europe/Brussels', $byId['Europe/Brussels']);
        self::assertStringStartsWith('UTC', $byId['Europe/Brussels']);
    }

    public function testEnsureIdAddsAConstructibleZoneMissingFromDefaultList(): void
    {
        // Cherche un fuseau constructible mais absent de listIdentifiers() (dépend
        // de la timezone database installée : ici 'GMT' convient, ailleurs un
        // alias de compat comme 'US/Eastern'). Skip si l'environnement n'en a aucun.
        $listed    = \DateTimeZone::listIdentifiers();
        $candidate = null;
        foreach (['GMT', 'US/Eastern', 'Zulu', 'US/Pacific'] as $z) {
            if (in_array($z, $listed, true)) {
                continue;
            }
            try {
                new \DateTimeZone($z);
            } catch (\Throwable) {
                continue;
            }
            $candidate = $z;
            break;
        }

        if ($candidate === null) {
            self::markTestSkipped('Aucun fuseau constructible non-listé disponible dans cet environnement.');
        }

        $ids = array_column(Timezones::options($candidate), 'id');

        self::assertContains($candidate, $ids, 'Le fuseau courant du profil doit rester sélectionnable.');
    }

    public function testEnsureIdIgnoresInvalidZone(): void
    {
        $ids = array_column(Timezones::options('Not/AZone'), 'id');

        self::assertNotContains('Not/AZone', $ids);
        self::assertNotEmpty($ids); // ne casse pas la liste
    }

    public function testEnsureIdDoesNotPolluteTheMemoizedBase(): void
    {
        // Un appel avec ensureId (qui peut ajouter un fuseau non-listé) ne doit
        // pas polluer les appels suivants sans ensureId (cache de base intact).
        Timezones::options('GMT');
        $ids = array_column(Timezones::options(), 'id');

        if (!in_array('GMT', \DateTimeZone::listIdentifiers(), true)) {
            self::assertNotContains('GMT', $ids, 'Le cache de base ne doit pas être pollué par un ensureId.');
        } else {
            self::assertContains('GMT', $ids);
        }
    }

    public function testOptionsAreSortedByCurrentOffset(): void
    {
        // Le libellé encode l'offset ; on vérifie que le 1er est <= au dernier
        // via une re-lecture de l'offset réel de chaque id (ordre croissant).
        $options = Timezones::options();
        $first   = (new \DateTimeZone($options[0]['id']))->getOffset(new \DateTimeImmutable('now'));
        $last    = (new \DateTimeZone($options[count($options) - 1]['id']))->getOffset(new \DateTimeImmutable('now'));

        self::assertLessThanOrEqual($last, $first);
    }
}
