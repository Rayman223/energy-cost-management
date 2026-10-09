<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\LoadProfileRepositoryInterface;
use App\Support\Dates;
use DateTimeImmutable;
use PDO;

/**
 * Accès à la table `load_profiles` : profils de charge servant à pondérer les
 * cotations de marché (#93).
 *
 * Même convention de clé que `dynamic_prices` — un instant UTC par créneau — pour que
 * la jointure poids ↔ cotations soit une simple égalité de chaîne côté calcul, sans
 * conversion de fuseau ni règle sur les changements d'heure.
 */
final class LoadProfileRepository implements LoadProfileRepositoryInterface
{
    /** Profil par défaut en Belgique : le RLP « normalisé » de l'offtake résidentiel. */
    public const DEFAULT_CODE = 'RLP0N';

    public const DEFAULT_COUNTRY = 'BE';

    /**
     * Forme d'un code de profil : majuscules, chiffres, « _ » et « - », 1 à 32
     * caractères (colonne VARCHAR(32)). Règle UNIQUE, appliquée à l'import comme à
     * l'enregistrement des grilles (#101) : un code accepté par une grille mais
     * refusé à l'import serait signalé manquant chaque mois sans remède possible.
     */
    public const CODE_PATTERN = '/^[A-Z0-9_-]{1,32}$/';

    /** Code normalisé (trim + majuscules), ou null s'il ne respecte pas {@see self::CODE_PATTERN}. */
    public static function normalizeCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function weightsBetween(
        string $code,
        string $country,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $resolutionMin = 15,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(slot_start, '%Y-%m-%d %H:%i:00') AS slot,
                    fraction
             FROM load_profiles
             WHERE code = :code
               AND country = :country
               AND resolution_min = :resolution
               AND slot_start >= :from
               AND slot_start <  :to
             ORDER BY slot_start"
        );
        $stmt->execute([
            'code'       => $code,
            'country'    => $country,
            'resolution' => $resolutionMin,
            'from'       => Dates::toDbString($from),
            'to'         => Dates::toDbString($to),
        ]);

        $map = [];
        /** @var array<int, array{slot: string, fraction: string}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $map[$row['slot']] = (float) $row['fraction'];
        }

        return $map;
    }

    public function availableCodes(string $country): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT code
             FROM load_profiles
             WHERE country = :country
             ORDER BY code'
        );
        $stmt->execute(['country' => $country]);

        /** @var list<string> $codes */
        $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $codes;
    }

    /**
     * Nombre de points par mois (UTC) et par résolution, sur `[$from, $to[` — de quoi
     * juger si un mois est importé en entier (#101).
     *
     * @return array<string, array<int, int>> 'Y-m' => [resolution_min => points]
     */
    public function pointsByMonth(string $code, string $country, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(slot_start, '%Y-%m') AS month,
                    resolution_min,
                    COUNT(*) AS points
             FROM load_profiles
             WHERE code = :code
               AND country = :country
               AND slot_start >= :from
               AND slot_start <  :to
             GROUP BY month, resolution_min
             ORDER BY month"
        );
        $stmt->execute([
            'code'    => $code,
            'country' => $country,
            'from'    => Dates::toDbString($from),
            'to'      => Dates::toDbString($to),
        ]);

        $out = [];
        /** @var array<int, array{month: string, resolution_min: int|string, points: int|string}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $out[$row['month']][(int) $row['resolution_min']] = (int) $row['points'];
        }

        return $out;
    }

    /**
     * Profils que des grilles tarifaires désignent réellement, tous comptes
     * confondus : les grilles électricité `indexed_monthly` portant un
     * `load_profile_code`, avec leur période de validité (#101).
     *
     * C'est ce qu'il faut tenir à jour — un profil importé que rien n'utilise ne
     * mérite pas de notification. Le pays retombe sur {@see self::DEFAULT_COUNTRY}
     * comme dans {@see \App\Service\CostCalculationService}, sans quoi la
     * vérification chercherait un profil que le calcul ne lira jamais.
     *
     * @return list<array{code: string, country: string, valid_from: string, valid_to: ?string}>
     */
    public function profilesInUse(): array
    {
        $stmt = $this->pdo->query(
            "SELECT load_profile_code AS code, country, valid_from, valid_to
             FROM tariff_grids
             WHERE energy_type = 'electricity'
               AND pricing_mode = 'indexed_monthly'
               AND load_profile_code IS NOT NULL
               AND load_profile_code <> ''
             ORDER BY load_profile_code, valid_from"
        );
        if ($stmt === false) {
            return [];
        }

        $out = [];
        /** @var array<int, array{code: string, country: ?string, valid_from: string, valid_to: ?string}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $out[] = [
                'code'       => $row['code'],
                'country'    => ($row['country'] ?? '') !== '' ? (string) $row['country'] : self::DEFAULT_COUNTRY,
                'valid_from' => $row['valid_from'],
                'valid_to'   => $row['valid_to'],
            ];
        }

        return $out;
    }

    /**
     * Insère / met à jour une série de poids. La clé unique
     * (code, country, resolution_min, slot_start) rend l'opération idempotente : un
     * fichier réimporté corrige les valeurs au lieu de les dupliquer.
     *
     * @param array<int, array{slot_start: DateTimeImmutable, fraction: float}> $weights
     * @return int Nombre de lignes traitées.
     */
    public function upsertWeights(string $code, string $country, int $resolutionMin, array $weights, string $source): int
    {
        if ($weights === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO load_profiles
                (code, country, slot_start, resolution_min, fraction, source)
            VALUES
                (:code, :country, :slot_start, :resolution, :fraction, :source)
            ON DUPLICATE KEY UPDATE
                fraction   = VALUES(fraction),
                source     = VALUES(source),
                created_at = CURRENT_TIMESTAMP
            SQL
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($weights as $weight) {
                $stmt->execute([
                    'code'       => $code,
                    'country'    => $country,
                    'slot_start' => Dates::toDbString($weight['slot_start']),
                    'resolution' => $resolutionMin,
                    'fraction'   => $weight['fraction'],
                    'source'     => $source,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return count($weights);
    }
}
