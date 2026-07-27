<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\BillPeriod;
use App\Domain\SpotFormula;
use App\Domain\SpotFormulaFit;
use App\Service\SpotFormulaFitter;
use PHPUnit\Framework\TestCase;

/**
 * Cœur algébrique du rapprochement facture (#229).
 *
 * Les jeux d'essai sont construits À L'ENVERS : on part d'un couple (coefficient, offset)
 * connu, on calcule le montant que ce couple facturerait, et on vérifie que le fitter le
 * retrouve. C'est la seule façon de tester une résolution sans réimplémenter la même
 * formule dans l'assertion.
 */
final class SpotFormulaFitterTest extends TestCase
{
    private const DELTA = 0.0001;

    /**
     * Un mois facturé par le couple (coef, offset) de référence.
     *
     * @param float $indexedBaseTtc Σ kWh × spot × (1+TVA) — pilote le prix moyen du mois.
     * @param float $coveredKwh     Σ kWh couverts.
     * @param float $uncoveredTtc   Constante hors formule.
     */
    private function period(
        int $month,
        float $indexedBaseTtc,
        float $coveredKwh,
        float $coefficient,
        float $offsetTtc,
        float $uncoveredTtc = 0.0,
    ): BillPeriod {
        $billed = $coefficient * $indexedBaseTtc + $offsetTtc * $coveredKwh + $uncoveredTtc;

        return new BillPeriod(
            year:           2026,
            month:          $month,
            billedTtc:      $billed,
            indexedBaseTtc: $indexedBaseTtc,
            coveredKwh:     $coveredKwh,
            uncoveredTtc:   $uncoveredTtc,
            // Le coût calculé n'entre pas dans la résolution (seul le facturé compte) ;
            // une valeur arbitraire suffit ici.
            computedTtc:    $billed - 10.0,
        );
    }

    public function testNoPeriodIsUndetermined(): void
    {
        $fit = (new SpotFormulaFitter())->fit([], new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $fit->mode);
        self::assertFalse($fit->isDetermined());
        self::assertNull($fit->offsetAtCurrentCoefficient);
    }

    /**
     * Un mois sans heure couverte n'apporte aucune équation : il ne doit pas compter comme
     * une période exploitable, sinon deux mois dont un vide passeraient pour un système
     * résoluble.
     */
    public function testPeriodWithoutCoverageIsIgnored(): void
    {
        $empty = new BillPeriod(2026, 3, 120.0, 0.0, 0.0, 0.0, 0.0);

        $fit = (new SpotFormulaFitter())->fit([$empty], new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_UNDETERMINED, $fit->mode);
    }

    /**
     * Un seul mois : une équation, deux inconnues. Aucun couple ne doit être proposé —
     * seulement les deux corrections à une inconnue.
     */
    public function testSinglePeriodProposesBothOneUnknownCorrections(): void
    {
        // 1000 kWh couverts, base indexée 200 €, aucune heure non couverte.
        // Facturé avec coef 1,08 et offset 0,02 → 1,08×200 + 0,02×1000 = 236 €.
        $period = $this->period(month: 1, indexedBaseTtc: 200.0, coveredKwh: 1000.0, coefficient: 1.08, offsetTtc: 0.02);

        // Formule actuellement saisie : neutre (coef 1,0, offset 0).
        $fit = (new SpotFormulaFitter())->fit([$period], new SpotFormula(1.0, 0.0));

        self::assertSame(SpotFormulaFit::MODE_SINGLE_PERIOD, $fit->mode);
        self::assertFalse($fit->isDetermined());

        // À coefficient figé à 1,0 : offset = (236 − 1,0×200) / 1000 = 0,036 €/kWh.
        self::assertEqualsWithDelta(0.036, $fit->offsetAtCurrentCoefficient, self::DELTA);
        // À offset figé à 0 : coefficient = 236 / 200 = 1,18.
        self::assertEqualsWithDelta(1.18, $fit->coefficientAtCurrentOffset, self::DELTA);

        // Les deux corrections annulent bien l'écart, chacune de son côté.
        self::assertEqualsWithDelta($period->billedTtc, $period->costFor(1.0, 0.036), self::DELTA);
        self::assertEqualsWithDelta($period->billedTtc, $period->costFor(1.18, 0.0), self::DELTA);
    }

    /**
     * Deux mois aux profils contrastés : le système 2×2 se résout exactement et doit
     * retrouver le couple d'origine au centime près.
     */
    public function testTwoContrastedPeriodsSolveExactly(): void
    {
        // Mois d'hiver : prix moyen indexé 0,25 €/kWh (250 / 1000).
        // Mois d'été   : prix moyen indexé 0,10 €/kWh (60 / 600).
        $winter = $this->period(month: 1, indexedBaseTtc: 250.0, coveredKwh: 1000.0, coefficient: 1.08, offsetTtc: 0.025);
        $summer = $this->period(month: 7, indexedBaseTtc: 60.0, coveredKwh: 600.0, coefficient: 1.08, offsetTtc: 0.025);

        $fit = (new SpotFormulaFitter())->fit([$winter, $summer], new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_EXACT, $fit->mode);
        self::assertTrue($fit->isDetermined());
        self::assertEqualsWithDelta(1.08, $fit->coefficient, self::DELTA);
        self::assertEqualsWithDelta(0.025, $fit->offsetTtc, self::DELTA);
        self::assertEqualsWithDelta(0.0, $fit->residualTtc, 0.01);
        self::assertFalse($fit->coefficientOutOfBounds);
    }

    /**
     * Les heures non couvertes sont une constante hors formule : les inclure dans le terme
     * indexé ferait dériver le coefficient. Même jeu que le test précédent, plus 40 € et
     * 30 € facturés au tarif classique — le couple retrouvé doit être inchangé.
     */
    public function testUncoveredCostIsExcludedFromTheResolution(): void
    {
        $winter = $this->period(1, 250.0, 1000.0, 1.08, 0.025, uncoveredTtc: 40.0);
        $summer = $this->period(7, 60.0, 600.0, 1.08, 0.025, uncoveredTtc: 30.0);

        $fit = (new SpotFormulaFitter())->fit([$winter, $summer], new SpotFormula());

        self::assertEqualsWithDelta(1.08, $fit->coefficient, self::DELTA);
        self::assertEqualsWithDelta(0.025, $fit->offsetTtc, self::DELTA);
    }

    /**
     * Deux mois au MÊME prix moyen indexé donnent deux équations colinéaires. Le système
     * paraît formellement résoluble mais amplifierait le moindre bruit de saisie : c'est
     * le cas « profils trop proches » que l'issue demande de signaler.
     */
    public function testTwoPeriodsWithIdenticalAveragePriceAreIllConditioned(): void
    {
        // Prix moyen identique (0,20 €/kWh) malgré des volumes différents.
        $a = $this->period(month: 1, indexedBaseTtc: 200.0, coveredKwh: 1000.0, coefficient: 1.08, offsetTtc: 0.02);
        $b = $this->period(month: 2, indexedBaseTtc: 120.0, coveredKwh: 600.0, coefficient: 1.08, offsetTtc: 0.02);

        $fit = (new SpotFormulaFitter())->fit([$a, $b], new SpotFormula(1.0, 0.0));

        self::assertSame(SpotFormulaFit::MODE_ILL_CONDITIONED, $fit->mode);
        self::assertFalse($fit->isDetermined());
        self::assertEqualsWithDelta(0.0, $fit->priceSpreadPct, self::DELTA);
        // Les corrections à une inconnue restent proposées : c'est la sortie utile du cas.
        self::assertNotNull($fit->offsetAtCurrentCoefficient);
        self::assertNotNull($fit->coefficientAtCurrentOffset);
    }

    /** Juste sous le seuil de 2 % d'écart relatif : encore refusé. */
    public function testPriceSpreadJustBelowThresholdIsRejected(): void
    {
        // Prix moyens 0,2000 et 0,2030 → écart relatif 1,5 %.
        $a = $this->period(1, 200.0, 1000.0, 1.08, 0.02);
        $b = $this->period(2, 203.0, 1000.0, 1.08, 0.02);

        $fit = (new SpotFormulaFitter())->fit([$a, $b], new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_ILL_CONDITIONED, $fit->mode);
        self::assertEqualsWithDelta(1.5, $fit->priceSpreadPct, self::DELTA);
    }

    /** Juste au-dessus du seuil : accepté, et le couple d'origine est retrouvé. */
    public function testPriceSpreadJustAboveThresholdIsAccepted(): void
    {
        // Prix moyens 0,2000 et 0,2050 → écart relatif 2,5 %.
        $a = $this->period(1, 200.0, 1000.0, 1.08, 0.02);
        $b = $this->period(2, 205.0, 1000.0, 1.08, 0.02);

        $fit = (new SpotFormulaFitter())->fit([$a, $b], new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_EXACT, $fit->mode);
        self::assertEqualsWithDelta(2.5, $fit->priceSpreadPct, self::DELTA);
        self::assertEqualsWithDelta(1.08, $fit->coefficient, self::DELTA);
        self::assertEqualsWithDelta(0.02, $fit->offsetTtc, self::DELTA);
    }

    /**
     * Trois mois cohérents : les moindres carrés retrouvent le couple exact et le résidu
     * reste nul — le mode change, pas le résultat.
     */
    public function testThreeConsistentPeriodsUseLeastSquares(): void
    {
        $periods = [
            $this->period(1, 250.0, 1000.0, 1.05, 0.03),
            $this->period(4, 150.0, 900.0, 1.05, 0.03),
            $this->period(7, 60.0, 600.0, 1.05, 0.03),
        ];

        $fit = (new SpotFormulaFitter())->fit($periods, new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_LEAST_SQUARES, $fit->mode);
        self::assertEqualsWithDelta(1.05, $fit->coefficient, self::DELTA);
        self::assertEqualsWithDelta(0.03, $fit->offsetTtc, self::DELTA);
        self::assertEqualsWithDelta(0.0, $fit->residualTtc, 0.01);
    }

    /**
     * Un mois incohérent (facture gonflée de 50 €) ne doit pas être avalé silencieusement :
     * le résidu doit remonter, c'est le seul signal que la formule n'explique pas tout.
     */
    public function testInconsistentPeriodShowsUpInTheResidual(): void
    {
        $periods = [
            $this->period(1, 250.0, 1000.0, 1.05, 0.03),
            $this->period(4, 150.0, 900.0, 1.05, 0.03),
            new BillPeriod(2026, 7, 123.0 + 50.0, 60.0, 600.0, 0.0, 0.0),
        ];

        $fit = (new SpotFormulaFitter())->fit($periods, new SpotFormula());

        self::assertSame(SpotFormulaFit::MODE_LEAST_SQUARES, $fit->mode);
        self::assertNotNull($fit->residualTtc);
        self::assertGreaterThan(1.0, $fit->residualTtc);
    }

    /**
     * Un coefficient résolu hors ]0 ; 5] signifie que l'écart ne vient pas de la formule
     * (montant saisi incluant le réseau et les taxes, par exemple). La valeur est renvoyée
     * pour diagnostic, mais SIGNALÉE : la saisir telle quelle serait de toute façon
     * refusée par la validation de /tariffs.
     */
    public function testOutOfBoundsCoefficientIsFlagged(): void
    {
        // Facturé ~10× la base indexée : compatible avec un total de facture, pas avec la
        // seule part énergie.
        $a = $this->period(1, 250.0, 1000.0, 12.0, 0.0);
        $b = $this->period(7, 60.0, 600.0, 12.0, 0.0);

        $fit = (new SpotFormulaFitter())->fit([$a, $b], new SpotFormula());

        self::assertTrue($fit->isDetermined());
        self::assertEqualsWithDelta(12.0, $fit->coefficient, self::DELTA);
        self::assertTrue($fit->coefficientOutOfBounds);
    }

    /**
     * Les propositions à une inconnue partent de la formule ACTUELLE, pas d'une formule
     * neutre : à coefficient 1,08 déjà saisi, l'offset proposé ne doit corriger que le
     * reliquat.
     */
    public function testOneUnknownCorrectionsStartFromTheCurrentFormula(): void
    {
        // Facturé avec coef 1,08 et offset 0,02 sur 1000 kWh / base 200 € → 236 €.
        $period = $this->period(1, 200.0, 1000.0, 1.08, 0.02);

        $fit = (new SpotFormulaFitter())->fit([$period], new SpotFormula(1.08, 0.0));

        // (236 − 1,08×200) / 1000 = 0,02 → exactement l'offset manquant.
        self::assertEqualsWithDelta(0.02, $fit->offsetAtCurrentCoefficient, self::DELTA);
    }
}
