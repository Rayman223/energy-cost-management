<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class TariffGrid
{
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
    ) {
    }

    /** Grille du catalogue communautaire partagé (gérée par un admin). */
    public function isShared(): bool
    {
        return $this->userId === null;
    }

    public function isActiveOn(DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return $this->validTo === null || $date <= $this->validTo;
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
     * @return array{vat_rate: float, lines: list<array{key: string, kind: string, amount: float, label: string|null}>}
     */
    public function toCalculationTariff(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = [
                'key'    => $line->key,
                'kind'   => $line->kind->value,
                'amount' => $line->amount,
                'label'  => $line->label,
            ];
        }

        return ['vat_rate' => $this->vatRate, 'lines' => $lines];
    }
}
