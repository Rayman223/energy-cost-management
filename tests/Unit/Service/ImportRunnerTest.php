<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\ImportRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Traduction des champs du formulaire d'import en surcharges de mapping.
 * L'orchestration transactionnelle, elle, est couverte par ImportRunnerDbTest.
 */
final class ImportRunnerTest extends TestCase
{
    public function testSimpleOverridesAreTrimmedAndKept(): void
    {
        $overrides = ImportRunner::parseOverrides([
            'ts_col'    => '  Date  ',
            'value_col' => 'Gaz naturel',
            'unit'      => 'l',
        ]);

        self::assertSame(['ts_col' => 'Date', 'value_col' => 'Gaz naturel', 'unit' => 'l'], $overrides);
    }

    public function testBlankFieldsProduceNoOverrides(): void
    {
        $overrides = ImportRunner::parseOverrides(['ts_col' => '', 'value_col' => '   ', 'unit' => '']);

        self::assertSame([], $overrides);
    }

    /** Le formulaire poste registre => colonne ; le preset attend colonne => registre. */
    public function testRegistersAreInvertedAndNormalized(): void
    {
        $overrides = ImportRunner::parseOverrides([
            'registers' => ['import_t1' => 'HP_Jour', 'export_t1' => '  Inj_Jour  '],
        ]);

        self::assertSame(['hp_jour' => 'import_t1', 'inj_jour' => 'export_t1'], $overrides['registers']);
    }

    /** Un index laissé vide n'est simplement pas importé. */
    public function testBlankRegistersAreDropped(): void
    {
        $overrides = ImportRunner::parseOverrides([
            'registers' => ['import_t1' => 'HP', 'import_t2' => '', 'export_t1' => '  ', 'production' => ''],
        ]);

        self::assertSame(['hp' => 'import_t1'], $overrides['registers']);
    }

    /**
     * Aucun index renseigné → pas de surcharge du tout : le preset garde son défaut
     * (une colonne par clé de registre), donc les fichiers déjà conformes passent.
     */
    public function testNoRegisterFilledLeavesPresetDefault(): void
    {
        $overrides = ImportRunner::parseOverrides([
            'registers' => ['import_t1' => '', 'import_t2' => ''],
        ]);

        self::assertArrayNotHasKey('registers', $overrides);
    }

    /**
     * Deux index sur la même colonne : l'inversion en écraserait un en silence et
     * l'import serait faux sans le signaler — on refuse explicitement.
     */
    public function testSameColumnOnTwoRegistersThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/deux index/');

        ImportRunner::parseOverrides([
            'registers' => ['import_t1' => 'Index', 'import_t2' => 'index'],
        ]);
    }

    public function testNonArrayRegistersIsIgnored(): void
    {
        $overrides = ImportRunner::parseOverrides(['registers' => 'nope']);

        self::assertSame([], $overrides);
    }
}
