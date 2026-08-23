<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Support\Dates;
use DateTimeImmutable;

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
 *   - `delta_pct`       : variation en % vs la MÊME fenêtre du mois précédent
 *                         (null si incalculable, cf. {@see build()})
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

    /**
     * Durée minimale de fenêtre écoulée sous laquelle aucune variation n'est
     * affichée (#5). Sur quelques heures, le rapport entre les deux mois est
     * dominé par l'heure du relevé et le rythme intra-journalier plutôt que par
     * une différence de consommation réelle : mieux vaut ne rien annoncer.
     */
    private const MIN_WINDOW_SECONDS = 86400;

    /** Fenêtre inexploitable : ni valeur du mois en cours, ni référence. */
    private const NO_WINDOW = ['from' => null, 'to' => null, 'prev_from' => null, 'prev_to' => null];

    public function __construct(
        private readonly LegacyDailyRepositoryInterface $elecRepo,
        private readonly CostCalculationService $costSvc,
    ) {
    }

    /**
     * @param array<string, mixed> $deltas Deltas élec du mois demandé, DÉJÀ calculés
     *        par l'appelant (la route les utilise aussi pour l'en-tête de section) :
     *        les repasser évite de refaire l'interpolation, les caches de
     *        getMonthlyDeltas() et getDeltasBetween() étant distincts.
     * @param DateTimeImmutable|null $asOf Instant de référence, utilisé quand le mois
     *        en cours n'a aucun relevé électricité et ne fournit donc pas de borne de
     *        fin. Défaut : maintenant, en UTC (fuseau de stockage).
     *
     * @return list<DashboardCard>
     */
    public function build(array $deltas, int $year, int $month, ?DateTimeImmutable $asOf = null): array
    {
        $now = $asOf ?? new DateTimeImmutable('now', Dates::utc());

        // Une fenêtre par source de relevés. L'électricité s'arrête au dernier index
        // connu — le repository a déjà clampé `to` dessus — tandis que le gaz et
        // l'eau, relevés bien plus rarement, se lisent jusqu'à aujourd'hui : les
        // faire dépendre du dernier relevé élec réduirait la card Gaz à un jour et
        // demi chez qui saisit son index électrique une fois par mois, et priverait
        // toutes les cards de badge dès que ce relevé tombe le 1er.
        $elecWindow    = self::comparisonWindow($year, $month, self::elecWindowEnd($deltas, $now));
        $utilityWindow = self::comparisonWindow($year, $month, $now);

        $prevFrom = $elecWindow['prev_from'];
        $prevTo   = $elecWindow['prev_to'];

        // Référence : la MÊME fenêtre, un mois plus tôt (#5). Sous le seuil, aucune
        // référence n'est chargée — les cards sortent alors sans badge, sans coûter
        // une requête pour une comparaison qu'on n'affichera pas.
        $prevDeltas = ($prevFrom === null || $prevTo === null)
            ? []
            : $this->elecRepo->getDeltasBetween(Dates::toDbString($prevFrom), Dates::toDbString($prevTo));

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
        //
        // Volume mesuré sur la fenêtre écoulée, et non projeté sur le mois entier
        // comme le faisait la voie « mois calendaire » (#5) : les deux mois sont
        // ainsi lus sur la même durée, et la valeur affichée s'aligne sur celle des
        // cards élec — toutes deux arrêtées au dernier relevé, comme l'annonce
        // l'en-tête de section.
        $gasVolume = $this->costSvc->periodGasVolume(...);
        $cards[] = $this->card(
            'gas',
            'orange',
            'dot--orange',
            self::volumeOver($utilityWindow['from'], $utilityWindow['to'], $gasVolume),
            self::volumeOver($utilityWindow['prev_from'], $utilityWindow['prev_to'], $gasVolume),
            'm³',
            true,
            'dash.gas',
        );

        $waterVolume = $this->costSvc->periodWaterVolume(...);
        $cards[] = $this->card(
            'water',
            'cyan',
            'dot--cyan',
            self::volumeOver($utilityWindow['from'], $utilityWindow['to'], $waterVolume),
            self::volumeOver($utilityWindow['prev_from'], $utilityWindow['prev_to'], $waterVolume),
            'm³',
            true,
            'dash.water',
        );

        return $cards;
    }

    /**
     * Borne de fin de la fenêtre électricité : le dernier index connu.
     *
     * `to` sort de {@see \App\Repository\ElectricityReadingRepository::getDeltasBetween()},
     * qui le clampe déjà sur le dernier relevé disponible — c'est le « dernier index
     * du jour » demandé par l'issue, et non une projection de fin de mois. Sans
     * aucun relevé élec ce mois-ci, les deltas sont vides et la fenêtre retombe sur
     * l'instant courant, comme pour le gaz et l'eau.
     *
     * @param array<string, mixed> $deltas
     */
    private static function elecWindowEnd(array $deltas, DateTimeImmutable $now): DateTimeImmutable
    {
        return (isset($deltas['to']) && is_string($deltas['to']) && $deltas['to'] !== '')
            ? Dates::fromDbString($deltas['to'])
            : $now;
    }

    /**
     * Fenêtre de comparaison du mois demandé, et son homologue du mois précédent.
     *
     * Le badge comparait le mois en cours — forcément partiel — à un mois précédent
     * COMPLET, d'où les « −99 % » des premiers jours du mois (#5). On compare
     * désormais deux fenêtres de même durée, toutes deux comptées depuis le 1er.
     *
     * @param DateTimeImmutable $end Fin de la fenêtre écoulée, propre à la source de
     *        relevés considérée ({@see elecWindowEnd()} pour l'électricité).
     * @return array{
     *     from: DateTimeImmutable|null,
     *     to: DateTimeImmutable|null,
     *     prev_from: DateTimeImmutable|null,
     *     prev_to: DateTimeImmutable|null,
     * } Bornes nulles quand rien n'est exploitable ; `prev_*` seules nulles sous
     *   {@see MIN_WINDOW_SECONDS}, la valeur du mois en cours restant affichable.
     */
    private static function comparisonWindow(int $year, int $month, DateTimeImmutable $end): array
    {
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), Dates::utc());
        $monthEnd   = $monthStart->modify('+1 month');

        // Un mois révolu, ou un relevé horodaté en avance, ne doit pas déborder du
        // mois demandé : la fenêtre resterait comparable, mais plus « à date ».
        $to = $end > $monthEnd ? $monthEnd : $end;

        $elapsed = $to->getTimestamp() - $monthStart->getTimestamp();
        if ($elapsed <= 0) {
            return self::NO_WINDOW;
        }

        if ($elapsed < self::MIN_WINDOW_SECONDS) {
            return ['from' => $monthStart, 'to' => $to, 'prev_from' => null, 'prev_to' => null];
        }

        // Même durée écoulée depuis le 1er du mois précédent (les bornes sont en
        // UTC : pas d'heure d'été à absorber). Clampée à la fin de ce mois, plus
        // court que le mois en cours un 31 mars.
        //
        // Un mois entier se compare en revanche au mois précédent ENTIER : le
        // calage à durée égale ne corrige que le biais du mois partiel, il n'a pas
        // à amputer février de trois jours parce que mars en compte 31.
        //
        // Ce clamp laisse volontairement les deux fenêtres se désaligner sur les
        // derniers jours d'un mois plus long que son prédécesseur : au 31 mars, mars
        // pèse ses 31 jours contre les 28 de février, et le badge annonce l'écart de
        // longueur (~+10 %). C'est exactement ce qu'il affichera le lendemain, mois
        // révolu — la fin de mois converge vers la comparaison de deux mois entiers
        // au lieu d'y sauter d'un coup à minuit.
        $prevFrom = $monthStart->modify('-1 month');
        $prevTo   = $to >= $monthEnd ? $monthStart : $prevFrom->modify('+' . $elapsed . ' seconds');
        if ($prevTo > $monthStart) {
            $prevTo = $monthStart;
        }

        return ['from' => $monthStart, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo];
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
     * Variation en % de la fenêtre écoulée du mois en cours par rapport à la MÊME
     * fenêtre du mois précédent (#5). Volontairement null si la référence est
     * absente ou nulle : un pourcentage calculé sur 0 n'a pas de sens.
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
     * Volume (m³) mesuré sur une fenêtre, ou null si la fenêtre est absente —
     * fenêtre inexploitable, ou référence écartée par {@see MIN_WINDOW_SECONDS}.
     *
     * @param callable(DateTimeImmutable, DateTimeImmutable): array<string, mixed> $volumeFor
     */
    private static function volumeOver(?DateTimeImmutable $from, ?DateTimeImmutable $to, callable $volumeFor): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return self::volume($volumeFor($from, $to));
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
