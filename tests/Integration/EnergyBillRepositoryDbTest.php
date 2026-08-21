<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\EnergyBill;
use App\Repository\EnergyBillRepository;
use App\Repository\UserRepository;

/**
 * Factures énergie saisies pour le rapprochement (#229). S'auto-skippe sans base de test
 * joignable.
 */
final class EnergyBillRepositoryDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    private int $otherUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users             = new UserRepository($this->pdo());
        $this->userId      = $users->create('https://iss.test', 'bill-owner', 'test', 'Bill Owner')->id;
        $this->otherUserId = $users->create('https://iss.test', 'bill-other', 'test', 'Other Owner')->id;
    }

    protected function clean(): void
    {
        foreach (['energy_bills', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testUpsertListAndDelete(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        self::assertSame([], $repo->listFor('electricity', 12));

        $repo->upsert('electricity', 2026, 6, 100.0, 121.0, 'facture 42');
        $bills = $repo->listFor('electricity', 12);

        self::assertCount(1, $bills);
        self::assertSame(2026, $bills[0]->year);
        self::assertSame(6, $bills[0]->month);
        self::assertEqualsWithDelta(100.0, $bills[0]->amountHtva, 0.0001);
        self::assertEqualsWithDelta(121.0, $bills[0]->amountTtc, 0.0001);
        self::assertSame('facture 42', $bills[0]->note);

        $repo->delete($bills[0]->id);
        self::assertSame([], $repo->listFor('electricity', 12));
    }

    /** La clé unique (user, énergie, année, mois) fait de la seconde saisie une mise à jour. */
    public function testUpsertOnTheSameMonthReplacesInsteadOfDuplicating(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        $repo->upsert('electricity', 2026, 6, 100.0, 121.0, 'première saisie');
        $repo->upsert('electricity', 2026, 6, 110.0, 133.1, 'correction');

        $bills = $repo->listFor('electricity', 12);
        self::assertCount(1, $bills);
        self::assertEqualsWithDelta(110.0, $bills[0]->amountHtva, 0.0001);
        self::assertSame('correction', $bills[0]->note);
    }

    /**
     * Un montant NULL doit ressortir NULL et non 0.0 : « pas de TTC sur ma facture » et
     * « 0 € facturé » ne veulent pas dire la même chose pour la résolution.
     */
    public function testNullAmountStaysNull(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        $repo->upsert('electricity', 2026, 5, 100.0, null);
        $bills = $repo->listFor('electricity', 12);

        self::assertEqualsWithDelta(100.0, $bills[0]->amountHtva, 0.0001);
        self::assertNull($bills[0]->amountTtc);
    }

    /** Tri décroissant : le mois le plus récent d'abord, c'est le contrat en cours. */
    public function testListIsOrderedMostRecentFirst(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        $repo->upsert('electricity', 2025, 12, null, 90.0);
        $repo->upsert('electricity', 2026, 6, null, 120.0);
        $repo->upsert('electricity', 2026, 1, null, 110.0);

        $periods = array_map(static fn ($bill): string => $bill->periodKey(), $repo->listFor('electricity', 12));

        self::assertSame(['2026-06', '2026-01', '2025-12'], $periods);
    }

    /**
     * Le scope tenant s'applique en lecture ET en suppression : un identifiant deviné ne
     * doit pas permettre d'effacer la facture d'un autre compte.
     */
    public function testTenantScopeIsolatesReadsAndDeletes(): void
    {
        $mine   = new EnergyBillRepository($this->pdo(), $this->userId);
        $theirs = new EnergyBillRepository($this->pdo(), $this->otherUserId);

        $mine->upsert('electricity', 2026, 6, null, 120.0);
        $theirs->upsert('electricity', 2026, 6, null, 999.0);

        self::assertCount(1, $mine->listFor('electricity', 12));
        self::assertEqualsWithDelta(120.0, $mine->listFor('electricity', 12)[0]->amountTtc, 0.0001);

        // Tentative de suppression croisée : sans effet.
        $theirBillId = $theirs->listFor('electricity', 12)[0]->id;
        $mine->delete($theirBillId);
        self::assertCount(1, $theirs->listFor('electricity', 12));
    }

    /**
     * LIMIT/OFFSET sont interpolés dans le SQL (MySQL refuse un paramètre lié à cet
     * emplacement) : ce test vérifie que le découpage est correct en base, et pas
     * seulement dans le fake.
     */
    public function testPaginationSlicesInChronologicalOrder(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        for ($month = 1; $month <= 15; $month++) {
            $repo->upsert('electricity', 2025 + intdiv($month - 1, 12), (($month - 1) % 12) + 1, null, 100.0 + $month);
        }

        self::assertSame(15, $repo->countFor('electricity'));

        $first = $repo->listFor('electricity', 12, 0);
        self::assertCount(12, $first);
        self::assertSame('2026-03', $first[0]->periodKey());

        $second = $repo->listFor('electricity', 12, 12);
        self::assertCount(3, $second);
        self::assertSame('2025-03', $second[0]->periodKey());

        // Aucun recouvrement entre les deux pages.
        $ids = array_map(static fn ($bill): int => $bill->id, array_merge($first, $second));
        self::assertCount(15, array_unique($ids));
    }

    /** countFor est scopé comme listFor : le compte d'un autre compte ne fuite pas. */
    public function testCountForIsTenantScoped(): void
    {
        $mine   = new EnergyBillRepository($this->pdo(), $this->userId);
        $theirs = new EnergyBillRepository($this->pdo(), $this->otherUserId);

        $mine->upsert('electricity', 2026, 6, null, 120.0);
        $theirs->upsert('electricity', 2026, 5, null, 99.0);
        $theirs->upsert('electricity', 2026, 4, null, 98.0);

        self::assertSame(1, $mine->countFor('electricity'));
        self::assertSame(2, $theirs->countFor('electricity'));
    }

    /**
     * `EnergyBill::MAX_AMOUNT` doit rester aligné sur la capacité réelle de la colonne :
     * la borne exacte passe, le premier centième au-dessus est refusé par la base. Élargir
     * `DECIMAL(12,4)` sans toucher la constante (ou l'inverse) casse ici — sinon la
     * validation de la route deviendrait soit trop stricte, soit inopérante.
     */
    public function testMaxAmountMatchesTheColumnCapacity(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        $repo->upsert('electricity', 2026, 6, null, EnergyBill::MAX_AMOUNT);
        self::assertEqualsWithDelta(
            EnergyBill::MAX_AMOUNT,
            $repo->listFor('electricity', 12)[0]->amountTtc,
            0.0001,
        );

        $this->expectException(\PDOException::class);
        $repo->upsert('electricity', 2026, 5, null, EnergyBill::MAX_AMOUNT + 0.01);
    }

    /** Le filtre par énergie évite qu'une future facture gaz remonte dans l'écran électricité. */
    public function testListFiltersByEnergyType(): void
    {
        $repo = new EnergyBillRepository($this->pdo(), $this->userId);

        $repo->upsert('electricity', 2026, 6, null, 120.0);
        $repo->upsert('gas', 2026, 6, null, 80.0);

        self::assertCount(1, $repo->listFor('electricity', 12));
        self::assertCount(1, $repo->listFor('gas', 12));
        self::assertEqualsWithDelta(80.0, $repo->listFor('gas', 12)[0]->amountTtc, 0.0001);
    }
}
