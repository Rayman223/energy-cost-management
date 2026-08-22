<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\AdvanceSchedule;
use App\Infrastructure\MigrationRunner;
use App\Repository\AdvanceScheduleRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test d'intégration de la migration 2026-08-21_exclusive_end_dates.sql (#1).
 * S'auto-skippe sans base de test joignable.
 *
 * Cette migration est la moitié SQL d'un changement dont l'autre moitié est en PHP
 * (`<=` devenu `<`). Prises ensemble, les deux doivent former un NO-OP EXACT : un
 * coût, un total payé, une grille active un jour donné doivent rester identiques
 * de part et d'autre de la bascule. C'est ce que vérifie ce test — non pas que
 * `valid_to` a bougé d'un jour, ce qui serait une paraphrase du SQL, mais que la
 * lecture du domaine après migration rend le MÊME verdict que la règle inclusive
 * rendait sur la donnée d'avant.
 *
 * L'enjeu justifie le test : le décalage est non idempotent et irrattrapable, et
 * appliquer une moitié sans l'autre décale tout d'un jour — donc potentiellement
 * d'un acompte entier — sans qu'aucune erreur ne soit levée.
 */
final class ExclusiveEndDatesMigrationDbTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../app/sql/migrations/2026-08-21_exclusive_end_dates.sql';

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))
            ->create('https://iss.test', 'bounds-owner', 'test', 'Bounds Owner')->id;
    }

    protected function clean(): void
    {
        foreach (['tariff_grid_lines', 'tariff_grids', 'energy_advances', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    /** Rejoue la migration telle que le runner l'exécuterait. */
    private function runMigration(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        self::assertIsString($sql, 'Migration introuvable : ' . self::MIGRATION);

        foreach (MigrationRunner::splitStatements($sql) as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /** Écrit une grille avec la borne de fin telle qu'elle était stockée AVANT #1. */
    private function seedLegacyGrid(string $name, string $validFrom, ?string $inclusiveEnd): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO tariff_grids (user_id, energy_type, name, valid_from, valid_to)
             VALUES (:uid, :type, :name, :from, :to)'
        );
        $stmt->execute([
            'uid'  => $this->userId,
            'type' => 'electricity',
            'name' => $name,
            'from' => $validFrom,
            'to'   => $inclusiveEnd,
        ]);
    }

    /** Idem pour un barème d'acompte. */
    private function seedLegacySchedule(float $amount, string $validFrom, ?string $inclusiveEnd, int $dueDay): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO energy_advances (user_id, energy_type, amount_monthly, valid_from, valid_to, due_day)
             VALUES (:uid, :type, :amount, :from, :to, :due)'
        );
        $stmt->execute([
            'uid'    => $this->userId,
            'type'   => 'electricity',
            'amount' => $amount,
            'from'   => $validFrom,
            'to'     => $inclusiveEnd,
            'due'    => $dueDay,
        ]);
    }

    /**
     * Le décalage porte sur les deux colonnes, et sur elles seules — une plage
     * ouverte le reste.
     */
    public function testShiftsBothEndBoundsByOneDayAndLeavesOpenRangesOpen(): void
    {
        $this->seedLegacyGrid('Fermée', '2026-01-01', '2026-12-31');
        $this->seedLegacyGrid('Ouverte', '2027-01-01', null);
        $this->seedLegacySchedule(120.0, '2026-01-01', '2026-06-30', 5);
        $this->seedLegacySchedule(150.0, '2026-07-01', null, 5);

        $this->runMigration();

        $grids = (new TariffRepository($this->pdo(), $this->userId, false))->findAll('electricity');
        $ends  = [];
        foreach ($grids as $grid) {
            $ends[$grid->name] = $grid->validTo?->format('Y-m-d');
        }

        self::assertSame('2027-01-01', $ends['Fermée']);
        self::assertNull($ends['Ouverte']);

        $schedules = (new AdvanceScheduleRepository($this->pdo(), $this->userId))->listFor('electricity');
        $scheduleEnds = array_map(
            static fn (AdvanceSchedule $s): ?string => $s->validTo?->format('Y-m-d'),
            $schedules,
        );

        self::assertContains('2026-07-01', $scheduleEnds);
        self::assertContains(null, $scheduleEnds);
    }

    /**
     * Le cœur du sujet : sur la donnée d'avant, la grille couvrait le 31/12 et pas
     * le 01/01 suivant. Après migration, le domaine — qui lit désormais la borne
     * comme exclue — doit rendre exactement le même verdict, jour par jour.
     */
    public function testGridCoverageIsUnchangedAcrossTheSwitch(): void
    {
        $this->seedLegacyGrid('A', '2026-01-01', '2026-12-31');

        $this->runMigration();

        $repo = new TariffRepository($this->pdo(), $this->userId, false);

        // Jours couverts avant la bascule (règle inclusive) → toujours couverts.
        foreach (['2026-01-01', '2026-06-15', '2026-12-31'] as $day) {
            self::assertNotNull(
                $repo->findActiveGrid('electricity', $this->at($day)),
                'Jour anciennement couvert devenu orphelin : ' . $day,
            );
        }

        // Jours hors plage avant la bascule → toujours hors plage.
        foreach (['2025-12-31', '2027-01-01'] as $day) {
            self::assertNull(
                $repo->findActiveGrid('electricity', $this->at($day)),
                'Jour anciennement hors plage devenu couvert : ' . $day,
            );
        }

        $grid = $repo->findAll('electricity')[0];
        self::assertTrue($grid->isActiveOn($this->at('2026-12-31')));
        self::assertFalse($grid->isActiveOn($this->at('2027-01-01')));
    }

    /**
     * Deux grilles saisies « à l'ancienne » (l'une close la veille du début de
     * l'autre) deviennent deux grilles qui se RECOLLENT : chaque jour reste
     * attribué à la même grille qu'avant, et le jour de bascule à la nouvelle.
     */
    public function testConsecutiveGridsStayConsecutiveAfterTheShift(): void
    {
        $this->seedLegacyGrid('A', '2026-01-01', '2026-06-30');
        $this->seedLegacyGrid('B', '2026-07-01', null);

        $this->runMigration();

        $repo = new TariffRepository($this->pdo(), $this->userId, false);

        self::assertSame('A', $repo->findActiveGrid('electricity', $this->at('2026-06-30'))?->name);
        self::assertSame('B', $repo->findActiveGrid('electricity', $this->at('2026-07-01'))?->name);
    }

    /**
     * Même exigence sur les acomptes, où l'unité d'erreur n'est pas le jour mais le
     * PRÉLÈVEMENT : un barème du 01/01 au 30/06 inclus prélevait six fois le 5 du
     * mois. Après migration, le compte doit être le même — sans quoi le solde
     * affiché change d'un acompte entier sans que rien ne l'ait demandé.
     */
    public function testInstalmentCountIsUnchangedAcrossTheSwitch(): void
    {
        $this->seedLegacySchedule(120.0, '2026-01-01', '2026-06-30', 5);

        $this->runMigration();

        $schedule = (new AdvanceScheduleRepository($this->pdo(), $this->userId))->listFor('electricity')[0];
        $dates    = $schedule->dueDatesWithin($this->at('2026-01-01'), $this->at('2027-01-01'));

        self::assertSame(
            ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05', '2026-06-05'],
            array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates),
        );
    }

}
