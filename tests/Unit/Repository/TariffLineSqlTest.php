<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Domain\ComponentKind;
use App\Domain\TariffUnitRate;
use App\Repository\Sql\TariffLineSql;
use PHPUnit\Framework\TestCase;

/**
 * Les agrégats par pays de /stats (#8) appliquent en SQL les règles que
 * TariffUnitRate porte en PHP, et le bloc privé compare les deux résultats côte à
 * côte. Une divergence ne planterait rien : elle afficherait un écart « moi vs la
 * moyenne » faux et crédible.
 *
 * D'où la génération des CASE depuis les enums plutôt que leur recopie. Ce test
 * vérifie que la génération couvre bien TOUS les cases — une regex ou une boucle
 * cassée rendrait sinon un CASE incomplet, dont le ELSE avalerait silencieusement
 * les kinds manquants.
 */
final class TariffLineSqlTest extends TestCase
{
    public function testWeightCaseCoversEveryKindWithItsPhpWeight(): void
    {
        $sql = TariffLineSql::perKwhWeightCase();

        foreach (ComponentKind::cases() as $kind) {
            $expected = sprintf(
                "WHEN '%s' THEN %s",
                $kind->value,
                number_format(TariffUnitRate::weight($kind), 4, '.', ''),
            );
            self::assertStringContainsString(
                $expected,
                $sql,
                sprintf('Le CASE SQL doit porter le poids PHP de %s.', $kind->value),
            );
        }

        // Un kind absent de l'enum (grille d'une version antérieure) est hors
        // périmètre, pas une erreur : poids nul.
        self::assertStringEndsWith('ELSE 0.0 END', $sql);
    }

    public function testWeightCaseUsesTheGivenColumn(): void
    {
        self::assertStringStartsWith('CASE x.kind ', TariffLineSql::perKwhWeightCase('x.kind'));
    }

    public function testCategoryCaseReproducesKindGroupsAndPrefersExplicitCategory(): void
    {
        $sql = TariffLineSql::categoryCase();

        foreach (ComponentKind::cases() as $kind) {
            self::assertStringContainsString(
                sprintf("WHEN '%s' THEN '%s'", $kind->value, $kind->group()),
                $sql,
                sprintf('Le mapping SQL doit reproduire ComponentKind::group() pour %s.', $kind->value),
            );
        }

        // La colonne `category` prime : elle seule distingue `distribution` de
        // `taxes`, que les kinds confondent.
        self::assertStringContainsString('COALESCE(NULLIF(l.category', $sql);
    }

    public function testKindListHoldsExactlyTheWeightedKinds(): void
    {
        $list = TariffLineSql::perKwhKindList();

        foreach (ComponentKind::cases() as $kind) {
            $needle  = "'" . $kind->value . "'";
            $weighs  = TariffUnitRate::weight($kind) !== 0.0;
            $present = str_contains($list, $needle);

            self::assertSame(
                $weighs,
                $present,
                sprintf('%s devrait %sfigurer dans la liste des kinds au kWh.', $kind->value, $weighs ? '' : 'ne pas '),
            );
        }
    }

    public function testGeneratedFragmentsOnlyInterpolateEnumLiterals(): void
    {
        // Garde anti-injection : tout ce qui est entre quotes dans les fragments
        // générés doit provenir de l'enum (ou être la chaîne vide du NULLIF).
        $known = array_merge(
            ComponentKind::values(),
            array_map(static fn (ComponentKind $k): string => $k->group(), ComponentKind::cases()),
            ['', 'taxes'],
        );

        foreach ([TariffLineSql::perKwhWeightCase(), TariffLineSql::categoryCase(), TariffLineSql::perKwhKindList()] as $sql) {
            preg_match_all("/'([^']*)'/", $sql, $matches);
            foreach ($matches[1] as $literal) {
                self::assertContains($literal, $known, sprintf('Littéral SQL inattendu : %s', $literal));
            }
        }
    }
}
