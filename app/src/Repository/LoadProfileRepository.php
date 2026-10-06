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
