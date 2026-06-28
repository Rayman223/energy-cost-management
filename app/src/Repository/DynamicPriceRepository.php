<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\DynamicPriceRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * Accès à la table `dynamic_prices` : prix day-ahead du marché spot.
 *
 * Les prix sont stockés en €/kWh HTVA (brut marché) ; la marge fournisseur et
 * la TVA sont appliquées au moment du calcul du coût, pas au stockage.
 */
final class DynamicPriceRepository implements DynamicPriceRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insère / met à jour une série de prix. La clé unique (energy_type, period_start)
     * garantit l'idempotence : ré-exécuter le cron écrase proprement les valeurs.
     *
     * @param array<int, array{period_start: DateTimeImmutable, resolution_min: int, price_eur_kwh: float}> $prices
     * @return int Nombre de lignes traitées.
     */
    public function upsertPrices(array $prices, string $source): int
    {
        if ($prices === []) {
            return 0;
        }

        $sql = <<<'SQL'
            INSERT INTO dynamic_prices
                (energy_type, period_start, period_end, resolution_min, price_eur_kwh, source)
            VALUES
                ('electricity', :period_start, :period_end, :resolution_min, :price_eur_kwh, :source)
            ON DUPLICATE KEY UPDATE
                period_end     = VALUES(period_end),
                resolution_min = VALUES(resolution_min),
                price_eur_kwh  = VALUES(price_eur_kwh),
                source         = VALUES(source),
                fetched_at     = CURRENT_TIMESTAMP
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();

        try {
            foreach ($prices as $price) {
                $start = $price['period_start'];
                $end   = $start->modify(sprintf('+%d minutes', $price['resolution_min']));

                $stmt->execute([
                    'period_start'   => $start->format('Y-m-d H:i:s'),
                    'period_end'     => $end->format('Y-m-d H:i:s'),
                    'resolution_min' => $price['resolution_min'],
                    'price_eur_kwh'  => $price['price_eur_kwh'],
                    'source'         => $source,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return count($prices);
    }

    /**
     * Prix moyen €/kWh (HTVA) par heure sur [$from, $to[.
     *
     * @return array<string, float> Map 'Y-m-d H:00:00' => prix moyen €/kWh HTVA.
     */
    public function getAveragePriceByHour(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(period_start, '%Y-%m-%d %H:00:00') AS hour,
                    AVG(price_eur_kwh) AS avg_price
             FROM dynamic_prices
             WHERE energy_type = 'electricity'
               AND period_start >= :from
               AND period_start <  :to
             GROUP BY hour
             ORDER BY hour"
        );
        $stmt->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to'   => $to->format('Y-m-d H:i:s'),
        ]);

        $map = [];
        /** @var array<int, array{hour: string, avg_price: string}> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $map[$row['hour']] = (float) $row['avg_price'];
        }

        return $map;
    }

    public function latestPeriodEnd(): ?DateTimeImmutable
    {
        $value = $this->pdo->query('SELECT MAX(period_end) FROM dynamic_prices')->fetchColumn();

        return is_string($value) ? new DateTimeImmutable($value) : null;
    }
}
