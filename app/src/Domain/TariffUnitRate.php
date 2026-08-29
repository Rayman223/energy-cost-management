<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Tarif indicatif au kWh d'une grille électricité (#8) : la somme pondérée des
 * seules composantes qui multiplient des kWh.
 *
 * SOURCE UNIQUE de la règle « quels kinds font un €/kWh, et avec quel poids ».
 * Elle est consommée par deux chemins qui doivent rester d'accord au centime :
 *   - le bloc privé de /stats, en PHP, sur la grille active du foyer ;
 *   - les agrégats par pays, en SQL, via {@see \App\Repository\Sql\TariffLineSql}
 *     qui GÉNÈRE son CASE depuis {@see self::WEIGHTS}.
 * Les deux comparés côte à côte, une divergence produirait un écart que personne
 * ne saurait expliquer — d'où la génération plutôt que la recopie.
 *
 * Ce que le tarif indicatif n'est PAS : un coût réel. Il ignore délibérément
 *   - les abonnements (`fixed_monthly` / `fixed_annual`), qui sont des €/mois et
 *     des €/an : les étaler au kWh exigerait une consommation de référence
 *     arbitraire, et rendrait le chiffre incomparable d'un foyer à l'autre ;
 *   - les crédits d'injection, qui portent sur l'énergie REINJECTÉE et non sur
 *     le kWh prélevé (même frontière que TariffCalculatorService::netCostPerKwh) ;
 *   - les paramètres de formule dynamique, qui ne sont pas des postes du tout.
 * Le coût réel tout compris reste l'affaire de
 * {@see \App\Service\TariffCalculatorService::netCostPerKwh()}, affiché à part.
 *
 * Convention bihoraire : une grille T1/T2 est ramenée à un prix unique par un mix
 * jour/nuit 50/50. Arbitraire mais transparent, et affiché comme tel dans la note
 * méthodologique de /stats. L'alternative — pondérer par la consommation agrégée —
 * coupleraît deux statistiques et rendrait le chiffre non reproductible d'un mois
 * sur l'autre.
 */
final class TariffUnitRate
{
    /** Part conventionnelle du volume attribuée au tarif jour (T1). */
    public const T1_SHARE = 0.5;

    /** Part conventionnelle du volume attribuée au tarif nuit (T2). */
    public const T2_SHARE = 0.5;

    /**
     * Poids de chaque composante dans le tarif au kWh.
     *
     * EXHAUSTIVE sur ComponentKind, par contrat : un case ajouté à l'enum sans
     * entrée ici fait échouer TariffUnitRateTest plutôt que de valoir zéro en
     * silence — un poids oublié ne doit pas se traduire par un prix sous-évalué
     * publié sans que personne ne s'en aperçoive.
     *
     * @var array<string, float>
     */
    private const WEIGHTS = [
        // Multiplient le volume total : poids plein.
        'energy_flat'      => 1.0,
        'per_kwh'          => 1.0,
        // Bihoraire : chacun ne porte que sa part du volume, sinon T1+T2 double-compte.
        'energy_t1'        => self::T1_SHARE,
        'per_kwh_t1'       => self::T1_SHARE,
        'energy_t2'        => self::T2_SHARE,
        'per_kwh_t2'       => self::T2_SHARE,
        // €/mois et €/an : pas un €/kWh (cf. docblock de classe).
        'fixed_monthly'    => 0.0,
        'fixed_annual'     => 0.0,
        // Crédits sur l'énergie réinjectée : hors du coût du kWh prélevé.
        'injection_t1'     => 0.0,
        'injection_t2'     => 0.0,
        // Paramètres de la formule dynamique, jamais des postes : un coefficient
        // 1,08 sommé ici facturerait 1,08 €/kWh.
        'spot_coefficient' => 0.0,
        'spot_offset'      => 0.0,
        // Eau : hors périmètre électricité, poids nul par sûreté.
        'per_m3'           => 0.0,
    ];

    /**
     * Poids d'une composante dans le tarif au kWh (0.0 = hors périmètre).
     *
     * Accès direct, sans repli : PHPStan prouve l'exhaustivité de la map, et un
     * `?? 0.0` serait donc du code mort — mais surtout il transformerait un case
     * ajouté sans poids en un silencieux « ne compte pas ». Sans lui, l'oubli est
     * signalé deux fois : par PHPStan (offset manquant) et par TariffUnitRateTest.
     */
    public static function weight(ComponentKind $kind): float
    {
        return self::WEIGHTS[$kind->value];
    }

    /** Un kind déclare-t-il un poids ? Sert au test d'exhaustivité. */
    public static function hasWeight(ComponentKind $kind): bool
    {
        return array_key_exists($kind->value, self::WEIGHTS);
    }

    /**
     * Tarif indicatif TTC au kWh d'une grille.
     *
     * Les montants de `tariff_grid_lines` sont saisis TTC : la somme l'est donc
     * aussi, sans retraitement. null quand la grille ne porte aucune composante
     * au kWh — typiquement une grille dynamique, dont la part énergie vient du
     * marché : publier « 0,08 €/kWh » (réseau et taxes seuls) serait trompeur.
     *
     * @param array{vat_rate: float, currency: string, lines: list<array{key: string, kind: string, amount: float, label: string|null, category: string}>} $tariff
     *        Format exact de {@see TariffGrid::toCalculationTariff()}.
     */
    public static function fromCalculationTariff(array $tariff): ?float
    {
        $total   = 0.0;
        $counted = false;

        foreach ($tariff['lines'] as $line) {
            $weight = self::weight(ComponentKind::fromStringOrDefault($line['kind']));
            if ($weight === 0.0) {
                continue;
            }

            $total  += $line['amount'] * $weight;
            $counted = true;
        }

        return $counted ? $total : null;
    }

    /**
     * Part hors TVA d'un montant TTC, au taux de SA grille.
     *
     * Toujours dérivée grille par grille avant d'être moyennée, jamais l'inverse :
     * moyenner des taux de TVA puis diviser donnerait un HTVA faux dès que deux
     * grilles diffèrent (même règle que {@see \App\Service\CostBreakdownAggregator}).
     */
    public static function htvaFromTtc(float $ttc, float $vatRatePercent): float
    {
        $divisor = 1.0 + ($vatRatePercent / 100.0);

        return $divisor > 0.0 ? $ttc / $divisor : $ttc;
    }
}
