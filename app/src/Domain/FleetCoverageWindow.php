<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;

/**
 * Jusqu'où un parc de compteurs COUVRE une période, quand certains compteurs ont
 * été fermés (#76).
 *
 * La borne de fin couverte a toujours été l'INTERSECTION des fenêtres de relevés
 * du parc — la fin la plus précoce. Le raisonnement reste juste : un compteur
 * qu'on a cessé de relever doit dégrader la couverture, mieux vaut un coût
 * annoncé partiel qu'un coût partiel annoncé complet.
 *
 * Mais un compteur **fermé** n'est pas un compteur qu'on a cessé de relever.
 * Depuis {@see Meter} (#55), l'application sait qu'aucun relevé n'est plus
 * attendu d'un compteur retiré ; continuer à lui opposer sa dernière date fige la
 * couverture de tous les rapports suivants, indéfiniment — chez tout foyer qui a
 * simplement REMPLACÉ son compteur.
 *
 * D'où la règle, qui généralise l'intersection sans la renier : **un compteur ne
 * limite la couverture que tant qu'il est vivant**. La période est balayée par
 * époques, délimitées par les fermetures :
 *
 *   - un compteur fermé avant le début de la période n'avait rien à y couvrir,
 *     il sort d'emblée ;
 *   - sur chaque époque, la fin la plus précoce du parc encore vivant s'oppose,
 *     comme avant — mais seulement si elle tombe AVANT la prochaine fermeture :
 *     c'est alors un vrai trou, un compteur a cessé d'être relevé de son vivant ;
 *   - sinon le parc a tenu jusqu'à cette fermeture : le compteur fermé sort à son
 *     tour et l'époque suivante est jugée sur les survivants — un successeur qui
 *     prend le relais rend donc bien la période complète.
 *
 * Quand plus personne ne survit, la dernière fin connue fait borne : un parc
 * entièrement éteint ne couvre rien au-delà, et la couverture doit le dire.
 *
 * Sans aucune fermeture — le parc de presque tout le monde, et le mono-compteur —
 * le premier tour rend `min(ends)` : exactement l'intersection d'avant, un seul
 * chemin de code et pas deux.
 *
 * Les instants circulent au format DATETIME de la base ('Y-m-d H:i:s', UTC, cf.
 * {@see Dates}) : ils viennent de `meter_readings.reading_at` et repartent tels
 * quels vers l'appelant, sans aller-retour de format qui pourrait les altérer.
 */
final class FleetCoverageWindow
{
    /**
     * Écart admis entre le dernier relevé d'un compteur et sa fermeture, avant de
     * considérer qu'il a cessé d'être relevé de son vivant.
     *
     * La marge n'est pas cosmétique : la date de fermeture est une borne EXCLUE
     * (cf. {@see Meter::closureInstantFor()}), un compteur fermé ne PEUT donc pas
     * porter de relevé à l'instant exact de sa fermeture — son dernier index lui
     * est toujours antérieur. Sans elle, le remplacement le plus propre se lirait
     * comme un trou. Un jour, comme la tolérance de couverture côté service.
     */
    public const CLOSURE_TOLERANCE_SECONDS = 86400;

    /**
     * Borne de fin réellement couverte par le parc, ou `null` si aucun compteur ne
     * porte de donnée.
     *
     * @param list<array{end: string, closed_at: string|null}> $segments Par compteur
     *        contributeur : sa fin de relevés sur la période, et l'instant de sa
     *        fermeture (`null` s'il est ouvert).
     * @param string $periodStart Début de la période DEMANDÉE, pas celui des données.
     */
    public static function coveredUntil(array $segments, string $periodStart): ?string
    {
        if ($segments === []) {
            return null;
        }

        // Fermés avant le début de la période : ils ne pouvaient rien en couvrir,
        // leur dernière date n'apprend donc rien sur elle.
        $alive = self::survivors($segments, self::timestamp($periodStart));

        // Parc entièrement retiré avant la période : on retombe sur l'intersection
        // nue. Le rapport ne porte alors que sur des relevés antérieurs aux
        // fermetures, et doit rester annoncé partiel.
        if ($alive === []) {
            return self::earliestEnd($segments);
        }

        while (true) {
            $end     = self::earliestEnd($alive);
            $closure = self::nextClosure($alive);

            // Plus aucune fermeture devant, ou un compteur vivant qui s'arrête
            // avant la prochaine : la fin la plus précoce fait foi, comme avant.
            if ($closure === null || self::timestamp($end) < $closure - self::CLOSURE_TOLERANCE_SECONDS) {
                return $end;
            }

            $alive = self::survivors($alive, $closure);

            // Le parc s'éteint à cette fermeture : personne ne prend le relais, la
            // couverture s'arrête au dernier relevé connu.
            if ($alive === []) {
                return $end;
            }
        }
    }

    /**
     * Compteurs encore vivants APRÈS cet instant — borne de fermeture exclue.
     *
     * @param  list<array{end: string, closed_at: string|null}> $segments
     * @return list<array{end: string, closed_at: string|null}>
     */
    private static function survivors(array $segments, int $instant): array
    {
        return array_values(array_filter(
            $segments,
            static fn (array $segment): bool => $segment['closed_at'] === null
                || self::timestamp($segment['closed_at']) > $instant,
        ));
    }

    /**
     * Fin de relevés la plus PRÉCOCE du lot — l'intersection.
     *
     * @param non-empty-list<array{end: string, closed_at: string|null}> $segments
     */
    private static function earliestEnd(array $segments): string
    {
        $earliest   = $segments[0]['end'];
        $earliestAt = self::timestamp($earliest);

        foreach ($segments as $segment) {
            $at = self::timestamp($segment['end']);
            if ($at < $earliestAt) {
                $earliest   = $segment['end'];
                $earliestAt = $at;
            }
        }

        return $earliest;
    }

    /**
     * Prochaine fermeture du lot, ou `null` si tous ses compteurs sont ouverts.
     *
     * @param list<array{end: string, closed_at: string|null}> $segments
     */
    private static function nextClosure(array $segments): ?int
    {
        $next = null;

        foreach ($segments as $segment) {
            if ($segment['closed_at'] === null) {
                continue;
            }

            $at = self::timestamp($segment['closed_at']);
            if ($next === null || $at < $next) {
                $next = $at;
            }
        }

        return $next;
    }

    private static function timestamp(string $instant): int
    {
        return Dates::fromDbString($instant)->getTimestamp();
    }
}
