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
     * Unités proposées par type d'énergie. La **première** est l'unité canonique
     * (celle du stockage : kWh pour l'électricité, m³ pour gaz/eau) et sert de
     * défaut (facteur 1). Source unique partagée par le formulaire, le JS de
     * bascule et la validation ci-dessous.
     *
     * @var array<string, list<string>>
     */
    public const UNITS = [
        'electricity' => ['kwh', 'wh'],
        'gas'         => ['m3'],
        'water'       => ['m3', 'l'],
    ];

    /** Facteur de conversion unité → unité canonique (kWh / m³). */
    private const UNIT_FACTORS = ['kwh' => 1.0, 'wh' => 0.001, 'm3' => 1.0, 'l' => 0.001];

    /**
     * @param array<string, string> $registerColumns colonne normalisée => register_key (électricité)
     * @param float $unitToCanonicalFactor facteur appliqué aux valeurs lues pour
     *        les ramener à l'unité canonique de stockage (kWh / m³).
     */
    private function __construct(
        public readonly string $energyType,
        public readonly string $timestampColumn,
        public readonly ?string $valueColumn,
        public readonly array $registerColumns,
        public readonly float $unitToCanonicalFactor = 1.0,
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
     *  - `unit`      : unité des valeurs du fichier (cf. {@see self::UNITS}) ;
     *                  convertie vers l'unité canonique de stockage (kWh / m³).
     *  - `registers` : tableau colonne => register_key (électricité), remplace les défauts.
     *
     * @param array{ts_col?: string, value_col?: string, unit?: string, registers?: array<string, string>} $overrides
     */
    public static function preset(string $energyType, array $overrides = []): self
    {
        $energyType = strtolower(trim($energyType));
        if (!in_array($energyType, self::ENERGY_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf('Type d\'énergie invalide « %s » (attendu : %s).', $energyType, implode(', ', self::ENERGY_TYPES))
            );
        }

        $unitFactor = self::unitFactor($energyType, (string) ($overrides['unit'] ?? ''));

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

            return new self($energyType, $tsCol, null, $registers, $unitFactor);
        }

        $valueCol = isset($overrides['value_col']) && trim($overrides['value_col']) !== ''
            ? self::norm($overrides['value_col'])
            : 'value';

        return new self($energyType, $tsCol, $valueCol, [], $unitFactor);
    }

    /**
     * Résout le facteur de conversion vers l'unité canonique du type d'énergie.
     *
     * Unité vide **ou** non proposée pour ce type → on retombe sur l'unité
     * canonique (la 1re de {@see self::UNITS}, facteur 1). Ce repli couvre le cas
     * d'une requête sans JavaScript : le `<select>` d'unité n'est alors pas
     * réactualisé au changement de type et peut soumettre l'unité d'un autre type
     * (ex. `wh` pour l'eau) ; on stocke alors la valeur brute en unité canonique
     * plutôt que d'échouer. On n'applique jamais un diviseur de sous-unité tant
     * que l'unité ne correspond pas explicitement au type — donc aucune conversion
     * erronée en silence.
     *
     * $energyType est déjà validé par {@see self::preset()} : la clé existe.
     */
    private static function unitFactor(string $energyType, string $unit): float
    {
        $allowed = self::UNITS[$energyType];
        $unit    = self::norm($unit);
        if ($unit === '' || !in_array($unit, $allowed, true)) {
            $unit = $allowed[0];
        }

        return self::UNIT_FACTORS[$unit];
    }

    private static function norm(string $column): string
    {
        return strtolower(trim($column));
    }
}
