<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * Récapitulatif de consommation d'une ANNÉE CIVILE, toutes énergies (#41).
 *
 * Pendant annuel de {@see DashboardCardsService} : la composition vit ici plutôt
 * que dans le template ou le contrôleur, ce qui la rend testable — en particulier
 * le masquage de la ligne solaire et le repli « volume seul » du gaz.
 *
 * Aucun calcul métier n'est refait : tout passe par les estimations à bornes
 * libres de {@see CostCalculationService}, qui interpolent les bornes et
 * découpent la période par grille tarifaire. Le kWh gaz en particulier est
 * converti avec le PCS de CHAQUE sous-période puis sommé
 * ({@see CostCalculationService::estimatePeriodGas()}) : demander l'année d'un
 * coup revient donc bien à sommer les mois, et non à appliquer un PCS unique.
 *
 * Les bornes sont celles de l'année en UTC — même référentiel que le reste du
 * dashboard (#21). La borne de fin EFFECTIVE, elle, est celle que rendent les
 * estimations : une année en cours est clampée sur le dernier relevé, sans
 * projection, et {@see build()} le signale par `partial`.
 *
 * @phpstan-type AnnualRegister array{key: string, kwh: float}
 * @phpstan-type AnnualBlock array{
 *     available: bool,
 *     reason_key: string|null,
 *     period_from: string|null,
 *     period_to: string|null,
 *     currency: string|null,
 *     cost: float|null,
 * }
 */
final class AnnualConsumptionService
{
    /** Bornes d'année acceptées, alignées sur la validation année/mois de l'API. */
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;

    /**
     * Registres électricité, dans l'ordre d'affichage. `delta_key` pointe vers la
     * clé produite par {@see \App\Repository\ElectricityReadingRepository::getDeltasBetween()}.
     *
     * Les quatre registres import/export sont toujours rendus, y compris à zéro :
     * un compteur mono-horaire affiche simplement T2 à 0, comme les cards
     * mensuelles. Le solaire, lui, est masqué quand il n'y a rien à montrer
     * (même convention que la card `solar` de {@see DashboardCardsService}).
     *
     * @var list<array{key: string, delta_key: string}>
     */
    private const ELECTRICITY_REGISTERS = [
        ['key' => 'import_t1', 'delta_key' => 'prelev_jour'],
        ['key' => 'import_t2', 'delta_key' => 'prelev_nuit'],
        ['key' => 'export_t1', 'delta_key' => 'injec_jour'],
        ['key' => 'export_t2', 'delta_key' => 'injec_nuit'],
    ];

    /**
     * Motif d'indisponibilité gaz qui n'en est pas vraiment un : les relevés sont
     * là, seule la grille manque. Le volume reste alors affichable.
     */
    private const GAS_NO_TARIFF = 'dash.reason.no_tariff_gas';

    public function __construct(private readonly CostCalculationService $costSvc)
    {
    }

    /**
     * Totaux de l'année civile `$year`, par énergie.
     *
     * @return array{
     *     year: int,
     *     from: string,
     *     to: string,
     *     partial: bool,
     *     electricity: array<string, mixed>,
     *     gas: array<string, mixed>,
     *     water: array<string, mixed>,
     * }
     */
    public function build(int $year): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), Dates::utc());
        $to   = $from->modify('+1 year');

        $electricity = $this->electricity($from, $to);
        $gas         = $this->gas($from, $to);
        $water       = $this->water($from, $to);

        return [
            'year'        => $year,
            'from'        => $from->format('Y-m-d'),
            'to'          => $to->format('Y-m-d'),
            'partial'     => self::isPartial([$electricity, $gas, $water], $to),
            'electricity' => $electricity,
            'gas'         => $gas,
            'water'       => $water,
        ];
    }

    /**
     * Électricité : un total par registre, plus le coût annuel.
     *
     * @return array<string, mixed>
     */
    private function electricity(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $estimate = $this->costSvc->estimatePeriodElectricity($from, $to);
        if (($estimate['available'] ?? false) !== true) {
            return self::unavailable($estimate);
        }

        /** @var array<string, mixed> $deltas */
        $deltas    = is_array($estimate['deltas'] ?? null) ? $estimate['deltas'] : [];
        $registers = [];
        foreach (self::ELECTRICITY_REGISTERS as $register) {
            $registers[] = [
                'key' => $register['key'],
                'kwh' => (float) ($deltas[$register['delta_key']] ?? 0.0),
            ];
        }

        // Production PV : absente de la liste (et non affichée à zéro) quand aucun
        // registre de production n'est déclaré ou qu'il n'a rien produit.
        $solar = $deltas['solar'] ?? null;
        if (is_numeric($solar) && (float) $solar > 0.0) {
            $registers[] = ['key' => 'production', 'kwh' => (float) $solar];
        }

        return self::available($estimate) + [
            'registers' => $registers,
            // Fenêtre réellement couverte par les relevés, distincte des bornes
            // demandées : c'est elle qui dit si l'année est complète.
            'data_from' => self::stringOrNull($deltas['data_from'] ?? null),
            'data_to'   => self::stringOrNull($deltas['data_to'] ?? null),
        ];
    }

    /**
     * Gaz : m³ et kWh (converti grille par grille), plus le coût annuel.
     *
     * Sans grille gaz configurée, l'estimation complète est indisponible mais le
     * VOLUME reste calculable : on replie sur {@see CostCalculationService::periodGasVolume()}
     * pour ne pas perdre les m³, en conservant le motif — l'UI peut ainsi montrer
     * les m³ et expliquer pourquoi le kWh manque.
     *
     * @return array<string, mixed>
     */
    private function gas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $estimate = $this->costSvc->estimatePeriodGas($from, $to);
        if (($estimate['available'] ?? false) === true) {
            return self::available($estimate) + [
                'm3'  => self::floatOrNull($estimate['delta_m3'] ?? null),
                'kwh' => self::floatOrNull($estimate['kwh'] ?? null),
                'pcs' => self::floatOrNull($estimate['pcs_coefficient'] ?? null),
            ];
        }

        if (($estimate['reason_key'] ?? null) !== self::GAS_NO_TARIFF) {
            return self::unavailable($estimate) + ['m3' => null, 'kwh' => null, 'pcs' => null];
        }

        $volume = $this->costSvc->periodGasVolume($from, $to);
        if (($volume['available'] ?? false) !== true) {
            return self::unavailable($volume) + ['m3' => null, 'kwh' => null, 'pcs' => null];
        }

        // Volume disponible, mais pas de tarif : la ligne reste utilisable et porte
        // le motif de l'estimation complète, pas celui du volume. `array_merge` et
        // non `+` : c'est bien le `reason_key` posé ici qui doit l'emporter sur le
        // `null` d'un bloc disponible.
        return array_merge(self::available($volume), [
            'reason_key' => self::GAS_NO_TARIFF,
            'm3'         => self::floatOrNull($volume['delta_m3'] ?? null),
            'kwh'        => null,
            'pcs'        => null,
        ]);
    }

    /**
     * Eau : m³, plus le coût annuel si une grille eau couvre la période.
     *
     * @return array<string, mixed>
     */
    private function water(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $estimate = $this->costSvc->estimatePeriodWater($from, $to);
        if (($estimate['available'] ?? false) !== true) {
            return self::unavailable($estimate) + ['m3' => null];
        }

        return self::available($estimate) + ['m3' => self::floatOrNull($estimate['delta_m3'] ?? null)];
    }

    /**
     * Champs communs d'un bloc disponible : fenêtre effective, devise et montant.
     *
     * @param  array<string, mixed> $estimate
     * @return array<string, mixed>
     */
    private static function available(array $estimate): array
    {
        /** @var array<string, mixed> $cost */
        $cost = is_array($estimate['cost'] ?? null) ? $estimate['cost'] : [];

        return [
            'available'   => true,
            'reason_key'  => null,
            'period_from' => self::stringOrNull($estimate['period_from'] ?? null),
            'period_to'   => self::stringOrNull($estimate['period_to'] ?? null),
            'currency'    => self::stringOrNull($estimate['currency'] ?? null),
            // Pas de grille tarifaire ⇒ pas de `cost` : un montant à 0 se lirait
            // comme « cette énergie ne coûte rien », ce qui est faux.
            'cost'        => self::floatOrNull($cost['total'] ?? null),
        ];
    }

    /**
     * Bloc indisponible : seul le motif est transmis, pour que l'UI dise pourquoi.
     *
     * @param  array<string, mixed> $estimate
     * @return array<string, mixed>
     */
    private static function unavailable(array $estimate): array
    {
        return [
            'available'   => false,
            'reason_key'  => self::stringOrNull($estimate['reason_key'] ?? null),
            'period_from' => null,
            'period_to'   => null,
            'currency'    => null,
            'cost'        => null,
        ];
    }

    /**
     * L'année est partielle dès qu'une énergie disponible s'arrête avant sa fin :
     * année en cours, ou flux de relevés interrompu. Aucune donnée du tout n'est
     * pas « partiel » — c'est « indisponible », que chaque bloc porte déjà.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private static function isPartial(array $blocks, DateTimeImmutable $to): bool
    {
        foreach ($blocks as $block) {
            if (($block['available'] ?? false) !== true) {
                continue;
            }

            // `data_to` (électricité) est la borne réellement mesurée ; `period_to`
            // (gaz/eau) est la borne interpolée de la fenêtre facturée. À défaut de
            // l'une, l'autre décrit la même chose : jusqu'où portent les données.
            $end = self::stringOrNull($block['data_to'] ?? null) ?? self::stringOrNull($block['period_to'] ?? null);
            if ($end !== null && Dates::fromDbString($end) < $to) {
                return true;
            }
        }

        return false;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
