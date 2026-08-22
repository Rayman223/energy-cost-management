<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class TariffGrid
{
    /**
     * Modes de tarification électricité valides (colonne `tariff_grids.pricing_mode`).
     *
     * Le mode appartient à la grille, donc au contrat, et non plus au profil (#245) :
     * il est ainsi versionné par valid_from/valid_to, et une bascule fixe ↔ dynamique
     * ne réécrit plus les périodes antérieures.
     *
     * @var list<string>
     */
    public const PRICING_MODES = ['fixed', 'dynamic_hourly', 'dynamic_quarter'];

    public const PRICING_MODE_DEFAULT = 'fixed';

    /** @param array<string,TariffLine> $lines  line_key => ligne (montant + kind + libellé) */
    public function __construct(
        public readonly int $id,
        public readonly string $energyType,
        public readonly string $name,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validTo,
        public readonly array $lines,
        public readonly ?float $pcsCoefficient = null,
        public readonly ?int $userId = null,
        public readonly ?string $country = null,
        public readonly string $currency = 'EUR',
        public readonly float $vatRate = 21.0,
        public readonly string $pricingMode = self::PRICING_MODE_DEFAULT,
    ) {
    }

    /**
     * Le contrat de CETTE grille indexe-t-il l'énergie sur le prix de marché ?
     *
     * Ne dit rien du kill-switch serveur (`dynamic_prices.enabled`) ni de la
     * disponibilité des prix : l'appelant doit toujours combiner avec
     * `DynamicPricing::isEnabled()`, faute de quoi une grille dynamique serait
     * calculée sans prix sur un serveur qui n'en importe pas.
     */
    public function isDynamic(): bool
    {
        return $this->pricingMode !== self::PRICING_MODE_DEFAULT;
    }

    /** Mode normalisé : toute valeur hors liste blanche retombe sur 'fixed'. */
    public static function normalizePricingMode(string $mode): string
    {
        return in_array($mode, self::PRICING_MODES, true) ? $mode : self::PRICING_MODE_DEFAULT;
    }

    /** Grille du catalogue communautaire partagé (gérée par un admin). */
    public function isShared(): bool
    {
        return $this->userId === null;
    }

    /**
     * Cette grille couvre-t-elle le jour donné ? Intervalle `[validFrom, validTo[`
     * — `valid_to` désigne le PREMIER JOUR NON COUVERT (#1, cf.
     * app/docs/date-bounds.md), si bien que le jour de bascule entre deux grilles
     * successives n'appartient qu'à la nouvelle.
     *
     * Les trois dates sont ramenées à minuit : les appelants passent tantôt un jour
     * nu (`TariffPeriodSplitter`), tantôt un instant horodaté, et comparer un
     * `14:00` à une borne stockée à minuit sortait la journée de sa propre grille.
     *
     * Une borne ouverte (`validTo === null`) court indéfiniment.
     */
    public function isActiveOn(DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0, 0);

        if ($day < $this->validFrom->setTime(0, 0, 0)) {
            return false;
        }

        return $this->validTo === null || $day < $this->validTo->setTime(0, 0, 0);
    }

    public function getLine(string $key, float $default = 0.0): float
    {
        return $this->lines[$key]->amount ?? $default;
    }

    /**
     * Export plat clé => montant (rétrocompat : `tariff_rates` des réponses JSON,
     * repli horaire du tarif dynamique, TariffController::mapGrid).
     *
     * @return array<string, float>
     */
    public function toTariffArray(): array
    {
        $out = [];
        foreach ($this->lines as $key => $line) {
            $out[$key] = $line->amount;
        }

        return $out;
    }

    /**
     * Représentation destinée au moteur de calcul générique : taux de TVA + liste
     * ordonnée des lignes typées.
     *
     * @return array{vat_rate: float, lines: list<array{key: string, kind: string, amount: float, label: string|null, category: string}>}
     */
    public function toCalculationTariff(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = [
                'key'      => $line->key,
                'kind'     => $line->kind->value,
                'amount'   => $line->amount,
                'label'    => $line->label,
                'category' => $line->category()->value,
            ];
        }

        return ['vat_rate' => $this->vatRate, 'lines' => $lines];
    }
}
