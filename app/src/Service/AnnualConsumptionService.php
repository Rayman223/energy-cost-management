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
 * dashboard (#21). La borne de fin EFFECTIVE, elle, dépend de l'énergie :
 * l'électricité est clampée sur le dernier relevé, tandis que le gaz et l'eau
 * PROLONGENT la dernière pente jusqu'à la borne demandée (`is_projection`). Un
 * total peut donc porter sur moins que l'année, ou au contraire projeter des mois
 * qui n'ont pas été relevés : {@see build()} l'annonce par `partial`, et chaque
 * bloc par `complete`.
 *
 * @phpstan-type AnnualRegister array{key: string, kwh: float}
 * @phpstan-type AnnualBlock array{
 *     available: bool,
 *     complete: bool,
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
            'partial'     => self::isPartial([$electricity, $gas, $water]),
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
            // Les relevés couvrent-ils VRAIMENT la fenêtre demandée ? Deux façons
            // de ne pas la couvrir, une par moteur : le flux s'arrête avant la fin
            // (`coverage_complete` à false, électricité comme gaz/eau), ou bien la
            // borne de fin est extrapolée en avant (`is_projection`, gaz/eau) — et
            // dans ce dernier cas le total AFFICHÉ contient des mois jamais relevés.
            'complete'    => ($estimate['coverage_complete'] ?? true) !== false
                && ($estimate['is_projection'] ?? false) !== true,
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
            'complete'    => false,
            'reason_key'  => self::stringOrNull($estimate['reason_key'] ?? null),
            'period_from' => null,
            'period_to'   => null,
            'currency'    => null,
            'cost'        => null,
        ];
    }

    /**
     * L'année est partielle dès qu'une énergie disponible ne couvre pas toute la
     * fenêtre : année en cours, ou flux de relevés interrompu. Aucune donnée du
     * tout n'est pas « partiel » — c'est « indisponible », que chaque bloc porte déjà.
     *
     * Le verdict vient de `complete`, donc des estimations elles-mêmes, et non
     * d'une comparaison de dates : `period_to` (gaz/eau) vaut TOUJOURS la borne
     * demandée, y compris quand elle est extrapolée — la comparer à la fin d'année
     * ne détecterait jamais rien.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private static function isPartial(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['available'] ?? false) === true && ($block['complete'] ?? true) !== true) {
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
