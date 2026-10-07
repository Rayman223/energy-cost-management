<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillPeriod;
use App\Domain\EnergyBill;
use App\Domain\LoadWeighting;
use App\Domain\SpotFormula;
use App\Domain\SpotFormulaFit;
use App\Repository\Contract\EnergyBillRepositoryInterface;

/**
 * Croise les montants facturés saisis avec le coût calculé, mois par mois, puis déduit le
 * couple (coefficient, offset) du contrat (#229).
 *
 * Découpage : ce service ne fait QUE l'aiguillage — lire les factures, rejouer le calcul
 * dynamique de chaque mois, en extraire la décomposition `spot_base`. Toute l'algèbre vit
 * dans {@see SpotFormulaFitter}, qui reste pur et testable sans base.
 *
 * Un mois peut être inexploitable pour plusieurs raisons légitimes (pas de relevés, pas de
 * prix de marché importés, mois à cheval sur deux contrats). Ces mois sont écartés du
 * calcul mais RESTITUÉS avec leur raison : les taire donnerait un couple déduit d'un
 * sous-ensemble sans que l'utilisateur puisse le savoir.
 */
final class BillReconciliationService
{
    /**
     * Énergie rapprochée, et la seule possible (#93).
     *
     * Le rapprochement cherche le couple (coefficient, offset) d'une formule indexée sur
     * un prix de marché. Or `dynamic_prices.energy_type` est un `ENUM('electricity')` : il
     * n'existe pas d'index spot gaz ou eau en base, et le calcul rejoué est
     * {@see CostCalculationService::estimateMonthElectricityDynamic()}. Le périmètre est
     * donc écrit ici une fois pour toutes, plutôt que passé en paramètre : une signature
     * générique laissait croire qu'une facture de gaz pouvait être rapprochée, ce qui
     * l'aurait confrontée à un coût d'électricité.
     */
    public const ENERGY_TYPE = 'electricity';

    /**
     * Raisons d'exclusion d'un mois. Exposées telles quelles à la vue, qui les traduit via
     * `reconciliation.skipped.<raison>`.
     */
    public const SKIP_NO_ESTIMATE = 'no_estimate';
    public const SKIP_NO_AMOUNT   = 'no_amount';
    public const SKIP_NO_COVERAGE = 'no_coverage';
    public const SKIP_MIXED_GRIDS = 'mixed_grids';
    public const SKIP_ESTIMATED_WEIGHTING = 'estimated_weighting';

    /**
     * Source unique des raisons, pour le garde-fou i18n (TemplateCatalogTest dérive
     * `reconciliation.skipped.<raison>`) : en ajouter une sans sa traduction rend la CI
     * rouge, plutôt que d'afficher une clé brute dans le tableau.
     *
     * @var list<string>
     */
    public const SKIP_REASONS = [
        self::SKIP_NO_ESTIMATE,
        self::SKIP_NO_AMOUNT,
        self::SKIP_NO_COVERAGE,
        self::SKIP_MIXED_GRIDS,
        self::SKIP_ESTIMATED_WEIGHTING,
    ];

    /**
     * Factures rapprochées par page. Douze mois couvrent un cycle de facturation complet,
     * jusqu'à la régularisation annuelle — au-delà, il s'agit d'un contrat antérieur, dont
     * les paramètres n'ont plus à être corrigés.
     *
     * La borne n'est pas cosmétique : chaque facture affichée déclenche un recalcul complet
     * du mois (interpolation, relevés horaires, prix de marché). Une page non bornée
     * dégénérerait avec l'historique.
     */
    public const PAGE_SIZE = 12;

    public function __construct(
        private readonly EnergyBillRepositoryInterface $billRepo,
        private readonly CostCalculationService $costService,
        private readonly SpotFormulaFitter $fitter = new SpotFormulaFitter(),
    ) {
    }

    /**
     * Rapproche une page de factures, de la plus récente à la plus ancienne.
     *
     * La résolution ne porte que sur la page affichée : mêler un contrat en cours à un
     * contrat antérieur produirait un couple moyen ne correspondant à aucun des deux.
     *
     * Le périmètre est l'électricité seule ({@see self::ENERGY_TYPE}).
     *
     * @param int $page Page 1-indexée ; ramenée dans les bornes disponibles.
     * @return array{
     *     rows: list<array{year: int, month: int, period: string, billed_ttc: float|null, computed_ttc: float|null, gap: float|null, covered_kwh: float, avg_indexed_price: float|null, note: string, id: int, skipped: string|null}>,
     *     fit: SpotFormulaFit,
     *     current: SpotFormula|null,
     *     currency: string|null,
     *     page: int,
     *     pages: int,
     *     total: int
     * }
     */
    public function reconcile(int $page = 1): array
    {
        $total = $this->billRepo->countFor(self::ENERGY_TYPE);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page  = max(1, min($page, $pages));

        $rows     = [];
        $periods  = [];
        $current  = null;
        $currency = null;

        foreach ($this->billRepo->listFor(self::ENERGY_TYPE, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE) as $bill) {
            $estimate = $this->costService->estimateMonthElectricityDynamic($bill->year, $bill->month);

            $skip = $this->skipReason($estimate);
            if ($skip !== null) {
                $rows[] = $this->row($bill, null, null, 0.0, null, $skip);
                continue;
            }

            /** @var array{indexed_ttc: float, covered_kwh: float, uncovered_ttc: float, formula_uniform: bool} $spotBase */
            $spotBase = $estimate['spot_base'];
            /** @var array<string, float|string|bool> $formula */
            $formula = $estimate['formula'];

            $vatRate = (float) $formula['vat_rate'];
            $billed  = $bill->ttcOrDerived($vatRate);
            if ($billed === null) {
                $rows[] = $this->row($bill, null, null, $spotBase['covered_kwh'], null, self::SKIP_NO_AMOUNT);
                continue;
            }

            $period = new BillPeriod(
                year:           $bill->year,
                month:          $bill->month,
                billedTtc:      $billed,
                indexedBaseTtc: $spotBase['indexed_ttc'],
                coveredKwh:     $spotBase['covered_kwh'],
                uncoveredTtc:   $spotBase['uncovered_ttc'],
                computedTtc:    (float) $estimate['energy_dynamic'],
            );

            // La formule courante et la devise viennent du mois le plus récent rapproché :
            // les factures sont listées du plus récent au plus ancien, et c'est le contrat
            // en cours que l'utilisateur cherche à corriger.
            $current ??= new SpotFormula(
                (float) $formula['spot_coefficient'],
                (float) $formula['spot_offset_ttc'],
            );
            $currency ??= is_string($estimate['currency'] ?? null) ? $estimate['currency'] : null;

            $rows[] = $this->row(
                $bill,
                $billed,
                $period->computedTtc,
                $period->coveredKwh,
                $period->averageIndexedPrice(),
                $period->isUsable() ? null : self::SKIP_NO_COVERAGE,
            );

            if ($period->isUsable()) {
                $periods[] = $period;
            }
        }

        return [
            'rows'     => $rows,
            'fit'      => $this->fitter->fit($periods, $current ?? new SpotFormula()),
            'current'  => $current,
            'currency' => $currency,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
        ];
    }

    /**
     * Pourquoi ce mois ne peut-il pas entrer dans la résolution ? null = exploitable.
     *
     * @param array<string, mixed> $estimate
     */
    private function skipReason(array $estimate): ?string
    {
        // `spot_base` n'est pas revérifié : buildDynamicResponse n'a qu'une seule sortie
        // `available: true`, et elle le porte toujours. L'invariant est verrouillé par
        // CostCalculationServiceTest::testSpotBaseReconstitutesEnergyDynamic().
        if (($estimate['available'] ?? false) !== true) {
            return self::SKIP_NO_ESTIMATE;
        }

        // Mois à cheval sur deux contrats aux paramètres différents : l'équation du mois
        // mêlerait deux couples et le résultat ne correspondrait à aucun des deux.
        if ($estimate['spot_base']['formula_uniform'] !== true) {
            return self::SKIP_MIXED_GRIDS;
        }

        // Contrat à prix unitaire mensuel (#93) : le prix du mois doit porter une forme
        // de consommation, sans quoi l'erreur de pondération serait absorbée par le
        // coefficient déduit — un couple faux présenté comme exact.
        //
        // Deux pondérations sur trois conviennent, et c'est contre-intuitif :
        //   - `standard_profile` est la MEILLEURE ici, bien qu'elle décrive un groupe et
        //     non l'utilisateur. On cherche les paramètres du FOURNISSEUR, et c'est avec
        //     ce profil (le RLP) qu'il calcule son prix : le prix reconstitué est alors
        //     le sien, donc le couple déduit est exact ;
        //   - `actual_load` reste acceptable, et c'est le seul recours sans profil
        //     importé. Elle décrit mieux la consommation réelle, mais s'écarte
        //     légèrement du prix facturé — l'écart passe dans le coefficient ;
        //   - `baseload` est écartée : elle ne porte AUCUNE information de forme, et
        //     sous-estime structurellement un profil résidentiel.
        //
        // Un mois non clos est écarté quelle que soit la pondération : son prix est
        // provisoire, Belpex_RLP_M n'étant publié qu'en fin de mois.
        //
        // Les modes `dynamic_*` exposent `load_weighting: null` et `monthly_partial:
        // false` ({@see CostCalculationService::monthlyMeta()}), et une réponse classique
        // ne porte pas ces clés du tout : le `?? null` couvre les deux cas et les laisse
        // intacts, sans aucune régression.
        $weighting = $estimate['load_weighting'] ?? null;
        if ($weighting === LoadWeighting::Baseload->value
            || ($estimate['monthly_partial'] ?? false) === true
        ) {
            return self::SKIP_ESTIMATED_WEIGHTING;
        }

        return null;
    }

    /**
     * @return array{year: int, month: int, period: string, billed_ttc: float|null, computed_ttc: float|null, gap: float|null, covered_kwh: float, avg_indexed_price: float|null, note: string, id: int, skipped: string|null}
     */
    private function row(
        EnergyBill $bill,
        ?float $billed,
        ?float $computed,
        float $coveredKwh,
        ?float $avgPrice,
        ?string $skipped,
    ): array {
        return [
            'id'                => $bill->id,
            'year'              => $bill->year,
            'month'             => $bill->month,
            'period'            => $bill->periodKey(),
            'billed_ttc'        => $billed !== null ? round($billed, 2) : null,
            'computed_ttc'      => $computed !== null ? round($computed, 2) : null,
            'gap'               => ($billed !== null && $computed !== null) ? round($billed - $computed, 2) : null,
            'covered_kwh'       => round($coveredKwh, 3),
            'avg_indexed_price' => $avgPrice !== null ? round($avgPrice, 6) : null,
            'note'              => $bill->note,
            'skipped'           => $skipped,
        ];
    }
}
