<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;

/**
 * Couverture du parc pendant sa vie : [opened_at, closed_at[ (#76, #81).
 * Les bornes inconnues restent ouvertes. L'intersection des relevés est évaluée
 * entre chaque mise en service ou fermeture, pour conserver les vrais trous.
 * Un intervalle sans compteur ne devient pas couvert grâce à un relais tardif.
 *
 * @phpstan-type Segment array{value: string, opened_at: string|null, closed_at: string|null}
 */
final class FleetCoverageWindow
{
    /** Un jour, comme CostCalculationService::coversPeriod(). */
    public const CLOSURE_TOLERANCE_SECONDS = 86400;

    /**
     * @param list<array{end: string, closed_at: string|null, opened_at?: string|null}> $segments
     */
    public static function coveredUntil(array $segments, string $periodStart): ?string
    {
        $bounds = [];
        foreach ($segments as $segment) {
            $bounds[] = [
                'value' => $segment['end'],
                'opened_at' => $segment['opened_at'] ?? null,
                'closed_at' => $segment['closed_at'],
            ];
        }

        return self::sweep($bounds, $periodStart, false);
    }

    /**
     * Balayage depuis la fin : un compteur neuf ne limite pas les dates précédant
     * sa pose, mais un premier relevé tardif après cette pose reste une lacune.
     *
     * @param list<array{start: string, opened_at: string|null, closed_at?: string|null}> $segments
     */
    public static function coveredSince(array $segments, string $periodEnd): ?string
    {
        $bounds = [];
        foreach ($segments as $segment) {
            $bounds[] = [
                'value' => $segment['start'],
                'opened_at' => $segment['opened_at'],
                'closed_at' => $segment['closed_at'] ?? null,
            ];
        }

        return self::sweep($bounds, $periodEnd, true);
    }

    /** @param list<Segment> $segments */
    private static function sweep(array $segments, string $boundary, bool $backwards): ?string
    {
        if ($segments === []) {
            return null;
        }

        $cursor = self::timestamp($boundary);
        $alive = self::activeAt($segments, $cursor, $backwards);
        if ($alive === []) {
            // Le parc ne couvre pas cette extrémité de la période : conserver
            // une borne de données, sans inventer de couverture sans compteur.
            return self::limitingValue($segments, $backwards);
        }

        while (true) {
            $value = self::limitingValue($alive, $backwards);
            $next = self::nextBoundary($segments, $cursor, $backwards);
            if ($next === null) {
                return $value;
            }

            $valueAt = self::timestamp($value);
            $gap = $backwards
                ? $valueAt > $next + self::CLOSURE_TOLERANCE_SECONDS
                : $valueAt < $next - self::CLOSURE_TOLERANCE_SECONDS;
            if ($gap) {
                return $value;
            }

            $alive = self::activeAt($segments, $next, $backwards);
            if ($alive === []) {
                return $value;
            }
            $cursor = $next;
        }
    }

    /**
     * En avant : juste après la frontière. En arrière : juste avant, donc une
     * fermeture à l'instant courant réintègre le compteur, une ouverture le retire.
     *
     * @param list<Segment> $segments
     * @return list<Segment>
     */
    private static function activeAt(array $segments, int $instant, bool $backwards): array
    {
        return array_values(array_filter($segments, static function (array $segment) use ($instant, $backwards): bool {
            $opened = $segment['opened_at'] !== null ? self::timestamp($segment['opened_at']) : null;
            $closed = $segment['closed_at'] !== null ? self::timestamp($segment['closed_at']) : null;

            return ($opened === null || ($backwards ? $opened < $instant : $opened <= $instant))
                && ($closed === null || ($backwards ? $closed >= $instant : $closed > $instant));
        }));
    }

    /** @param non-empty-list<Segment> $segments */
    private static function limitingValue(array $segments, bool $backwards): string
    {
        $values = array_column($segments, 'value');

        return $backwards ? max($values) : min($values);
    }

    /** @param list<Segment> $segments */
    private static function nextBoundary(array $segments, int $cursor, bool $backwards): ?int
    {
        $next = null;
        foreach ($segments as $segment) {
            foreach ([$segment['opened_at'], $segment['closed_at']] as $boundary) {
                if ($boundary === null) {
                    continue;
                }
                $at = self::timestamp($boundary);
                if ($backwards ? $at >= $cursor : $at <= $cursor) {
                    continue;
                }
                if ($next === null || ($backwards ? $at > $next : $at < $next)) {
                    $next = $at;
                }
            }
        }

        return $next;
    }

    private static function timestamp(string $instant): int
    {
        return Dates::fromDbString($instant)->getTimestamp();
    }
}
