<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Infrastructure\MeterTopology;
use InvalidArgumentException;

/**
 * Correspondance colonnes de fichier → cibles d'import, pour un type d'énergie.
 *
 * Construite depuis un **preset** par type d'énergie, avec **surcharges**
 * optionnelles (nom de colonne d'horodatage/valeur, ou mapping colonne→registre
 * pour l'électricité). Les noms de colonnes sont normalisés (trim + minuscules),
 * cohérents avec l'en-tête normalisé de {@see RowSource::fromCsv()}.
 */
final class ImportMapping
{
    public const ENERGY_TYPES = ['electricity', 'gas', 'water'];

    /**
     * @param array<string, string> $registerColumns colonne normalisée => register_key (électricité)
     */
    private function __construct(
        public readonly string $energyType,
        public readonly string $timestampColumn,
        public readonly ?string $valueColumn,
        public readonly array $registerColumns,
    ) {
    }

    public function isElectricity(): bool
    {
        return $this->energyType === 'electricity';
    }

    /**
     * Construit un mapping depuis le preset du type d'énergie + surcharges.
     *
     * Surcharges reconnues :
     *  - `ts_col`    : nom de la colonne d'horodatage.
     *  - `value_col` : nom de la colonne de valeur (gaz/eau).
     *  - `registers` : tableau colonne => register_key (électricité), remplace les défauts.
     *
     * @param array{ts_col?: string, value_col?: string, registers?: array<string, string>} $overrides
     */
    public static function preset(string $energyType, array $overrides = []): self
    {
        $energyType = strtolower(trim($energyType));
        if (!in_array($energyType, self::ENERGY_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Type d\'énergie invalide « %s » (attendu : %s).', $energyType, implode(', ', self::ENERGY_TYPES))
            );
        }

        $tsCol = isset($overrides['ts_col']) && trim($overrides['ts_col']) !== ''
            ? self::norm($overrides['ts_col'])
            : 'timestamp';

        if ($energyType === 'electricity') {
            $registers = [];
            if (isset($overrides['registers']) && $overrides['registers'] !== []) {
                foreach ($overrides['registers'] as $col => $registerKey) {
                    $registerKey = strtolower(trim($registerKey));
                    if (!in_array($registerKey, MeterTopology::ELECTRICITY_REGISTERS, true)) {
                        throw new InvalidArgumentException(sprintf(
                            'Registre invalide « %s » (attendu : %s).',
                            $registerKey,
                            implode(', ', MeterTopology::ELECTRICITY_REGISTERS)
                        ));
                    }
                    $registers[self::norm($col)] = $registerKey;
                }
            } else {
                // Défaut : chaque registre est une colonne nommée comme sa clé.
                foreach (MeterTopology::ELECTRICITY_REGISTERS as $key) {
                    $registers[$key] = $key;
                }
            }

            return new self($energyType, $tsCol, null, $registers);
        }

        $valueCol = isset($overrides['value_col']) && trim($overrides['value_col']) !== ''
            ? self::norm($overrides['value_col'])
            : 'counter_m3';

        return new self($energyType, $tsCol, $valueCol, []);
    }

    private static function norm(string $column): string
    {
        return strtolower(trim($column));
    }
}
