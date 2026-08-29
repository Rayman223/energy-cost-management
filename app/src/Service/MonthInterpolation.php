<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Résultat de l'interpolation linéaire de la consommation d'un compteur sur un
 * mois calendaire (voir MonthlyConsumptionInterpolator). Soit indisponible (avec
 * une raison), soit l'index théorique à minuit aux deux bornes + métadonnées.
 *
 * `startKind` / `endKind` qualifient chaque borne :
 *   - 'exact'        : un relevé tombe pile à minuit ;
 *   - 'interpolated' : minuit est encadré par deux relevés (règle de trois) ;
 *   - 'extrapolated' : minuit est hors de la plage des relevés (pente du segment
 *                      de bord). `isProjection` = true uniquement quand la borne de
 *                      FIN est extrapolée en avant (mois en cours projeté).
 *
 * `startTs` / `endTs` portent les DEUX MÊMES bornes en epoch UTC. Elles font
 * doublon avec `monthStart`/`monthEnd` en apparence seulement : ces dernières sont
 * formatées sans fuseau (`format('Y-m-d H:i:s')`) alors qu'elles ont été construites
 * en UTC, si bien que les re-parser rend un instant décalé du fuseau applicatif.
 * Tout calcul qui redécoupe la période doit partir de `startTs`/`endTs` (#255).
 */
final class MonthInterpolation
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
        public readonly ?string $reasonKey,
        public readonly float $indexStart,
        public readonly float $indexEnd,
        public readonly float $monthlyDelta,
        public readonly int $days,
        public readonly int $calendarDays,
        public readonly string $monthStart,
        public readonly string $monthEnd,
        public readonly string $startKind,
        public readonly string $endKind,
        public readonly bool $isProjection,
        public readonly int $startTs,
        public readonly int $endTs,
    ) {
    }

    /**
     * @param string $reason    Texte technique, conservé pour l'API et les logs.
     * @param string $reasonKey Clé de catalogue (`common.reason.*`) que les pages
     *                          traduisent dans la langue du visiteur (#20).
     */
    public static function unavailable(string $reason, string $reasonKey): self
    {
        return new self(false, $reason, $reasonKey, 0.0, 0.0, 0.0, 0, 0, '', '', '', '', false, 0, 0);
    }

    public static function of(
        float $indexStart,
        float $indexEnd,
        float $monthlyDelta,
        int $days,
        int $calendarDays,
        string $monthStart,
        string $monthEnd,
        string $startKind,
        string $endKind,
        bool $isProjection,
        int $startTs,
        int $endTs,
    ): self {
        return new self(
            true,
            null,
            null,
            $indexStart,
            $indexEnd,
            $monthlyDelta,
            $days,
            $calendarDays,
            $monthStart,
            $monthEnd,
            $startKind,
            $endKind,
            $isProjection,
            $startTs,
            $endTs,
        );
    }
}
