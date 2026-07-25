<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\LegacyDailyRepositoryInterface;

/**
 * Construit la ligne de cards « consommation du mois en cours » du dashboard
 * (#215) : électricité (import/export), solaire, gaz et eau.
 *
 * La logique de composition vit ici plutôt que dans le template : c'est ce qui
 * rend testable le rendu conditionnel — en particulier le masquage complet de
 * la card solaire quand aucune production n'est disponible.
 *
 * Chaque card produite est un tableau plat consommé tel quel par
 * `app/templates/dashboard.php` :
 *   - `label_key`       : clé de traduction du libellé
 *   - `tone` / `dot`    : classes CSS de la variante colorée et de la pastille
 *   - `value`           : valeur du mois en cours (null → « — »)
 *   - `unit`/`decimals` : unité affichée et précision
 *   - `delta_pct`       : variation en % vs mois précédent complet (null si
 *                         incalculable)
 *   - `is_new`          : le mois précédent est connu et vaut 0 alors que le
 *                         mois en cours est consommé — un pourcentage serait une
 *                         division par zéro, le template affiche « nouveau »
 *   - `lower_is_better` : sens « favorable » de la variation (consommation vs
 *                         production/injection), pour la couleur
 *
 * @phpstan-type DashboardCard array{
 *     key: string,
 *     label_key: string,
 *     tone: string,
 *     dot: string,
 *     value: float|null,
 *     unit: string,
 *     decimals: int,
 *     delta_pct: float|null,
 *     is_new: bool,
 *     lower_is_better: bool,
 * }
 */
final class DashboardCardsService
{
    /**
     * Cards électricité, dans l'ordre d'affichage. `delta_key` pointe vers la
     * clé produite par ElectricityReadingRepository::getMonthlyDeltas().
     *
     * @var list<array{key: string, delta_key: string, tone: string, dot: string, lower_is_better: bool}>
     */
    private const ELECTRICITY_CARDS = [
        ['key' => 'import_t1', 'delta_key' => 'prelev_jour', 'tone' => 'amber', 'dot' => 'dot--amber',     'lower_is_better' => true],
        ['key' => 'import_t2', 'delta_key' => 'prelev_nuit', 'tone' => 'amber', 'dot' => 'dot--amber-dim', 'lower_is_better' => true],
        ['key' => 'export_t1', 'delta_key' => 'injec_jour',  'tone' => 'blue',  'dot' => 'dot--blue',      'lower_is_better' => false],
        ['key' => 'export_t2', 'delta_key' => 'injec_nuit',  'tone' => 'blue',  'dot' => 'dot--blue-dim',  'lower_is_better' => false],
    ];

    public function __construct(
        private readonly LegacyDailyRepositoryInterface $elecRepo,
        private readonly CostCalculationService $costSvc,
    ) {
    }

    /**
     * @param array<string, mixed> $deltas Deltas élec du mois demandé, DÉJÀ calculés
     *        par l'appelant (la route les utilise aussi pour l'en-tête de section) :
     *        les repasser évite de refaire l'interpolation, les caches de
     *        getMonthlyDeltas() et getMonthlyDeltasForMonth() étant distincts.
     *
     * @return list<DashboardCard>
     */
    public function build(array $deltas, int $year, int $month): array
    {
        $prevYear  = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;

        $prevDeltas = $this->elecRepo->getMonthlyDeltasForMonth($prevYear, $prevMonth);

        $cards = [];

        foreach (self::ELECTRICITY_CARDS as $spec) {
            $cards[] = $this->card(
                $spec['key'],
                $spec['tone'],
                $spec['dot'],
                self::floatOrNull($deltas[$spec['delta_key']] ?? null),
                self::floatOrNull($prevDeltas[$spec['delta_key']] ?? null),
                'kWh',
                $spec['lower_is_better'],
            );
        }

        // Solaire : card entièrement omise sans production ce mois-ci (registre
        // « production » absent → null, ou production nulle) — elle n'occupe
        // alors aucune colonne de la grille (#215).
        $solar = self::floatOrNull($deltas['solar'] ?? null);
        if ($solar !== null && $solar > 0.0) {
            $cards[] = $this->card(
                'solar',
                'green',
                'dot--green',
                $solar,
                self::floatOrNull($prevDeltas['solar'] ?? null),
                'kWh',
                false,
            );
        }

        // Gaz & eau : libellés déjà présents au catalogue (dash.gas / dash.water),
        // réutilisés tels quels plutôt que dupliqués sous dash.card.*.
        $cards[] = $this->card(
            'gas',
            'orange',
            'dot--orange',
            self::volume($this->costSvc->monthGasVolume($year, $month)),
            self::volume($this->costSvc->monthGasVolume($prevYear, $prevMonth)),
            'm³',
            true,
            'dash.gas',
        );

        $cards[] = $this->card(
            'water',
            'cyan',
            'dot--cyan',
            self::volume($this->costSvc->estimateMonthWater($year, $month)),
            self::volume($this->costSvc->estimateMonthWater($prevYear, $prevMonth)),
            'm³',
            true,
            'dash.water',
        );

        return $cards;
    }

    /**
     * @return DashboardCard
     */
    private function card(
        string $key,
        string $tone,
        string $dot,
        ?float $value,
        ?float $previous,
        string $unit,
        bool $lowerIsBetter,
        ?string $labelKey = null,
    ): array {
        return [
            'key'             => $key,
            'label_key'       => $labelKey ?? 'dash.card.' . $key,
            'tone'            => $tone,
            'dot'             => $dot,
            'value'           => $value,
            'unit'            => $unit,
            'decimals'        => 3,
            'delta_pct'       => self::variation($value, $previous),
            'is_new'          => self::isNew($value, $previous),
            'lower_is_better' => $lowerIsBetter,
        ];
    }

    /**
     * Variation en % du mois en cours par rapport au mois précédent COMPLET.
     * Volontairement null si la référence est absente ou nulle : un pourcentage
     * calculé sur 0 n'a pas de sens.
     */
    private static function variation(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Première consommation depuis un mois de référence à zéro : le pourcentage
     * est impossible, mais l'information « ça démarre ce mois-ci » est réelle et
     * mérite mieux qu'un badge vide (premier mois de relevés, compteur remplacé).
     *
     * À distinguer d'une référence ABSENTE (mois sans relevé exploitable), où
     * l'on ne sait rien : dans ce cas aucun badge n'est affiché.
     */
    private static function isNew(?float $current, ?float $previous): bool
    {
        return $current !== null && $current > 0.0 && $previous !== null && $previous <= 0.0;
    }

    /**
     * Volume (m³) extrait d'une réponse de CostCalculationService, ou null si
     * la période n'est pas exploitable.
     *
     * @param array<string, mixed> $estimate
     */
    private static function volume(array $estimate): ?float
    {
        if (($estimate['available'] ?? false) !== true) {
            return null;
        }

        return self::floatOrNull($estimate['delta_m3'] ?? null);
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
