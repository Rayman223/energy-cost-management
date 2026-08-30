<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Convention de fuseau horaire de l'application : toutes les dates sont stockées
 * en UTC en base (colonnes DATETIME sans offset), indépendamment du fuseau du
 * serveur MariaDB (forcé à `+00:00` sur la connexion) et du fuseau PHP par défaut.
 * L'affichage est ensuite reconverti vers le fuseau propre à chaque utilisateur.
 *
 * Ces helpers matérialisent cette convention aux frontières d'E/S : on normalise
 * en UTC avant d'écrire, et on relit en UTC. Robuste même si l'objet fourni porte
 * un autre fuseau (ex. une saisie parsée avec un offset côté client).
 */
final class Dates
{
    /** Formate un instant en chaîne DATETIME MySQL exprimée en UTC. */
    public static function toDbString(DateTimeInterface $moment): string
    {
        return (new DateTimeImmutable('@' . $moment->getTimestamp()))
            ->setTimezone(self::utc())
            ->format('Y-m-d H:i:s');
    }

    /** Reconstruit un instant à partir d'une chaîne DATETIME lue en base (interprétée en UTC). */
    public static function fromDbString(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, self::utc());
    }

    public static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    /**
     * Instant courant, exprimé dans le fuseau de stockage.
     *
     * Explicitement UTC, et non le fuseau PHP par défaut : celui-ci suit
     * `config.timezone` ({@see \App\bootstrap}), qu'un environnement non conforme
     * à la convention peut avoir laissé ailleurs qu'à UTC.
     */
    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    /**
     * Mois calendaire courant, lu en UTC : la source de vérité unique du « quel
     * mois sommes-nous » (#21).
     *
     * Les fenêtres du dashboard sont bâties en UTC (mois du 1er 00:00 UTC au 1er
     * du mois suivant). Dériver le mois de `date('Y')`/`date('n')` — donc du fuseau
     * PHP — désaligne les deux dès que celui-ci n'est pas UTC : au basculement de
     * mois, la fenêtre demandée est encore à venir et rien n'est comparable.
     *
     * @return array{int, int} [année, mois]
     */
    public static function currentYearMonth(): array
    {
        return self::yearMonthOf(self::nowUtc());
    }

    /**
     * Couple (année, mois) du mois calendaire auquel appartient $moment, LU EN UTC
     * quel que soit le fuseau que l'objet porte — un 1er juin 00:30 à Kiritimati
     * (UTC+14) appartient encore à mai côté stockage.
     *
     * @return array{int, int} [année, mois]
     */
    public static function yearMonthOf(DateTimeInterface $moment): array
    {
        $utc = (new DateTimeImmutable('@' . $moment->getTimestamp()))->setTimezone(self::utc());

        return [(int) $utc->format('Y'), (int) $utc->format('n')];
    }

    /**
     * Jour civil courant de l'utilisateur, ramené à minuit UTC — le référentiel
     * des bornes de validité stockées en DATE (ex. `energy_advances.valid_from`,
     * relue par {@see \App\Domain\AdvanceSchedule::fromRow()}).
     *
     * Comparer ces bornes au « today » du serveur décalerait le résultat d'un jour
     * entier de part et d'autre de minuit local : un barème s'afficherait comme
     * échu la veille de son terme, ou encore actif le lendemain.
     *
     * Un fuseau illisible (donnée héritée, base éditée à la main) retombe sur UTC :
     * un profil corrompu ne doit pas rendre la page inaccessible.
     */
    public static function todayIn(string $timezone): DateTimeImmutable
    {
        $localDay = (new DateTimeImmutable('now', self::zone($timezone)))->format('Y-m-d');

        return new DateTimeImmutable($localDay . ' 00:00:00', self::utc());
    }

    /**
     * Instant UTC auquel commence le jour civil $day (format `Y-m-d`) dans $timezone.
     *
     * Distinct de {@see todayIn()}, qui ramène un jour civil à minuit UTC : ici le
     * jour est bien ANCRÉ dans son fuseau, si bien que le 1er avril d'un contrat
     * belge commence le 31 mars à 22:00 UTC. C'est ce qu'il faut pour tarifer une
     * bascule de grille (#16) : la frontière d'une sous-période est minuit chez
     * l'utilisateur, pas minuit à Greenwich.
     *
     * Nuit de bascule où minuit local n'existe pas (rare, ex. `America/Santiago`) :
     * PHP rend l'instant suivant valide (01:00 locale), ce qui reste le début réel
     * de la journée.
     */
    public static function startOfDayIn(string $day, string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($day . ' 00:00:00', self::zone($timezone)))
            ->setTimezone(self::utc());
    }

    /**
     * Fuseau nommé, avec repli sur UTC : un identifiant illisible (donnée héritée,
     * base éditée à la main) ne doit pas rendre une page inaccessible.
     */
    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            return self::utc();
        }
    }
}
