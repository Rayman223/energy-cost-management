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
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            $zone = self::utc();
        }

        $localDay = (new DateTimeImmutable('now', $zone))->format('Y-m-d');

        return new DateTimeImmutable($localDay . ' 00:00:00', self::utc());
    }
}
