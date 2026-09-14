<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;

/**
 * Quelle part d'une période un parc de compteurs COUVRE réellement, quand les
 * compteurs entrent et sortent du parc (#76, #81).
 *
 * Les deux bornes ont toujours été l'INTERSECTION des fenêtres de relevés du parc
 * — début le plus tardif, fin la plus précoce. Le raisonnement reste juste : un
 * compteur qu'on a cessé de relever, ou qu'on n'a pas encore relevé, doit dégrader
 * la couverture, mieux vaut un coût annoncé partiel qu'un coût partiel annoncé
 * complet.
 *
 * Mais un compteur **fermé** n'est pas un compteur qu'on a cessé de relever, et un
 * compteur **pas encore posé** n'est pas un compteur qu'on a oublié de relever.
 * Depuis {@see Meter}, l'application connaît les deux bornes du cycle de vie
 * (`closed_on` #55, `opened_on` #81) ; sans elles, remplacer son compteur figeait
 * la couverture de tous les rapports suivants, et en ajouter un la figeait pour
 * tous les rapports antérieurs — indéfiniment des deux côtés.
 *
 * D'où la règle, qui généralise l'intersection sans la renier : **un compteur ne
 * limite la couverture que tant qu'il est vivant**. La période est balayée par
 * époques, délimitées par les entrées et sorties du parc :
 *
 *   - un compteur hors service sur toute la période (fermé avant son début, posé
 *     après sa fin) n'avait rien à y couvrir : il sort d'emblée ;
 *   - sur chaque époque, la borne la plus contraignante du parc vivant s'oppose,
 *     comme avant — mais seulement si elle tombe du mauvais côté de la prochaine
 *     porte : c'est alors un vrai trou, un compteur n'a pas été relevé DE SON
 *     VIVANT ;
 *   - sinon le parc a tenu jusqu'à cette porte : le compteur qui la franchit sort
 *     à son tour et l'époque suivante est jugée sur les autres — un successeur qui
 *     prend le relais rend donc bien la période complète.
 *
 * Quand plus personne ne reste, la dernière borne connue fait foi : un parc éteint
 * (ou pas encore né) ne couvre rien au-delà, et la couverture doit le dire.
 *
 * Sans aucune date de cycle de vie — le parc de presque tout le monde, et le
 * mono-compteur — le premier tour rend `min(ends)` et `max(starts)` : exactement
 * l'intersection d'avant, un seul chemin de code et pas deux.
 *
 * **Les deux côtés sont le même balayage dans un miroir temporel.** Le début n'est
 * que la fin vue à l'envers : « la fin la plus précoce » devient « le début le plus
 * tardif », « la prochaine fermeture » devient « la dernière mise en service ».
 * D'où un seul {@see sweep()}, orienté par un signe — deux implémentations
 * symétriques auraient divergé à la première correction.
 *
 * Les instants circulent au format DATETIME de la base ('Y-m-d H:i:s', UTC, cf.
 * {@see Dates}) : ils viennent de `meter_readings.reading_at` et repartent tels
 * quels vers l'appelant, sans aller-retour de format qui pourrait les altérer.
 */
final class FleetCoverageWindow
{
    /**
     * Écart admis entre le dernier (ou premier) relevé d'un compteur et la borne
     * de son cycle de vie, avant de considérer qu'il n'a pas été relevé de son
     * vivant.
     *
     * La marge n'est pas cosmétique : la date de fermeture est une borne EXCLUE
     * (cf. {@see Meter::closureInstantFor()}), un compteur fermé ne PEUT donc pas
     * porter de relevé à l'instant exact de sa fermeture — son dernier index lui
     * est toujours antérieur. Sans elle, le remplacement le plus propre se lirait
     * comme un trou. Un jour, comme la tolérance de couverture côté service.
     */
    public const LIFECYCLE_TOLERANCE_SECONDS = 86400;

    /**
     * Borne de fin réellement couverte par le parc, ou `null` si aucun compteur ne
     * porte de donnée.
     *
     * @param list<array{start: string, end: string, opened_at: string|null, closed_at: string|null}> $segments
     *        Par compteur contributeur : les bornes de ses relevés sur la période,
     *        et celles de son cycle de vie (`null` = depuis toujours / pour toujours).
     * @param string $periodStart Début de la période DEMANDÉE, pas celui des données.
     */
    public static function coveredUntil(array $segments, string $periodStart): ?string
    {
        return self::sweep(self::project($segments, true), $periodStart, 1);
    }

    /**
     * Borne de début réellement couverte par le parc, ou `null` si aucun compteur
     * ne porte de donnée.
     *
     * @param list<array{start: string, end: string, opened_at: string|null, closed_at: string|null}> $segments
     * @param string $periodEnd Fin de la période DEMANDÉE, pas celle des données.
     */
    public static function coveredFrom(array $segments, string $periodEnd): ?string
    {
        return self::sweep(self::project($segments, false), $periodEnd, -1);
    }

    /**
     * Réduit chaque compteur au couple qui intéresse le côté balayé : sa borne de
     * relevés, et la porte du cycle de vie qui la relativise.
     *
     * @param  list<array{start: string, end: string, opened_at: string|null, closed_at: string|null}> $segments
     * @return list<array{bound: string, gate: string|null}>
     */
    private static function project(array $segments, bool $towardsEnd): array
    {
        $projected = [];

        foreach ($segments as $segment) {
            $projected[] = $towardsEnd
                ? ['bound' => $segment['end'],   'gate' => $segment['closed_at']]
                : ['bound' => $segment['start'], 'gate' => $segment['opened_at']];
        }

        return $projected;
    }

    /**
     * Le balayage par époques, orienté.
     *
     * `$direction` vaut 1 vers la fin et -1 vers le début. Multiplier chaque
     * instant par ce signe rend les deux sens IDENTIQUES : « la plus précoce »
     * devient toujours « la plus petite », « après la porte » devient toujours
     * « plus grand que la porte ». C'est le miroir temporel décrit en tête de
     * classe, et la seule raison pour laquelle ce code n'existe qu'en un
     * exemplaire.
     *
     * @param list<array{bound: string, gate: string|null}> $segments
     * @param string $periodBound Borne de la période demandée du côté OPPOSÉ au balayage.
     */
    private static function sweep(array $segments, string $periodBound, int $direction): ?string
    {
        if ($segments === []) {
            return null;
        }

        // Compteurs hors service sur toute la période : ils ne pouvaient rien en
        // couvrir, leurs dates n'apprennent donc rien sur elle.
        $alive = self::stillIn($segments, self::timestamp($periodBound) * $direction, $direction);

        // Parc entièrement hors service : on retombe sur l'intersection nue. Le
        // rapport ne porte alors que sur des relevés hors cycle de vie, et doit
        // rester annoncé partiel.
        if ($alive === []) {
            return self::mostBinding($segments, $direction);
        }

        while (true) {
            $bound = self::mostBinding($alive, $direction);
            $gate  = self::nextGate($alive, $direction);

            // Plus aucune porte devant, ou un compteur vivant dont les relevés
            // s'arrêtent (ou commencent) du mauvais côté d'elle : sa borne fait
            // foi, comme avant.
            if ($gate === null || self::timestamp($bound) * $direction < $gate - self::LIFECYCLE_TOLERANCE_SECONDS) {
                return $bound;
            }

            $alive = self::stillIn($alive, $gate, $direction);

            // Le parc se vide à cette porte : personne ne prend le relais, la
            // couverture s'arrête à la dernière borne connue.
            if ($alive === []) {
                return $bound;
            }
        }
    }

    /**
     * Compteurs encore dans le parc AU-DELÀ de cet instant orienté — la porte
     * elle-même est exclue, un compteur fermé le 15 ne couvre pas le 15.
     *
     * @param  list<array{bound: string, gate: string|null}> $segments
     * @return list<array{bound: string, gate: string|null}>
     */
    private static function stillIn(array $segments, int $instant, int $direction): array
    {
        return array_values(array_filter(
            $segments,
            static fn (array $segment): bool => $segment['gate'] === null
                || self::timestamp($segment['gate']) * $direction > $instant,
        ));
    }

    /**
     * Borne la plus CONTRAIGNANTE du lot — la fin la plus précoce, ou le début le
     * plus tardif. C'est l'intersection.
     *
     * @param non-empty-list<array{bound: string, gate: string|null}> $segments
     */
    private static function mostBinding(array $segments, int $direction): string
    {
        $binding   = $segments[0]['bound'];
        $bindingAt = self::timestamp($binding) * $direction;

        foreach ($segments as $segment) {
            $at = self::timestamp($segment['bound']) * $direction;
            if ($at < $bindingAt) {
                $binding   = $segment['bound'];
                $bindingAt = $at;
            }
        }

        return $binding;
    }

    /**
     * Prochaine porte du lot dans le sens du balayage (instant ORIENTÉ), ou `null`
     * si aucun de ses compteurs n'en a.
     *
     * @param list<array{bound: string, gate: string|null}> $segments
     */
    private static function nextGate(array $segments, int $direction): ?int
    {
        $next = null;

        foreach ($segments as $segment) {
            if ($segment['gate'] === null) {
                continue;
            }

            $at = self::timestamp($segment['gate']) * $direction;
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
