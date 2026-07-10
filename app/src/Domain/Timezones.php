<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Options du sélecteur de fuseau horaire (page compte). Chaque option porte
 * l'identifiant IANA exact (valeur du <select>) et un libellé lisible incluant
 * l'offset UTC courant, ex. « UTC+2 - Europe/Brussels ». Triées par offset
 * courant puis par identifiant.
 *
 * Perf : la liste complète (~425 fuseaux) est coûteuse à bâtir (un DateTimeZone
 * par identifiant). Comme l'offset ne varie qu'aux transitions d'heure d'été,
 * elle est mémoïsée par jour calendaire au sein du process (réutilisée d'un
 * rendu à l'autre). Tolérance : un libellé/tri peut refléter l'offset d'avant
 * transition durant le jour d'un changement d'heure — sans incidence (la valeur
 * soumise reste l'identifiant IANA exact).
 */
final class Timezones
{
    /** @var array{day: string, list: list<array{id: string, offset: int, label: string}>}|null */
    private static ?array $cache = null;

    /**
     * Liste des fuseaux, triés (offset puis id).
     *
     * @param  ?string $ensureId Identifiant à garantir présent (ex. fuseau
     *                           courant du profil), même s'il n'est plus listé
     *                           par la version installée de la timezone database.
     *                           Ignoré s'il n'est pas constructible.
     * @return list<array{id: string, label: string}>
     */
    public static function options(?string $ensureId = null): array
    {
        $list = self::sortedByOffset();

        if ($ensureId !== null && $ensureId !== ''
            && !in_array($ensureId, DateTimeZone::listIdentifiers(), true)
            && self::isValid($ensureId)) {
            $offset = (new DateTimeZone($ensureId))->getOffset(new DateTimeImmutable('now'));
            $list[] = ['id' => $ensureId, 'offset' => $offset, 'label' => self::label($ensureId, $offset)];
            usort($list, self::compare(...));
        }

        return array_map(static fn (array $o): array => ['id' => $o['id'], 'label' => $o['label']], $list);
    }

    /**
     * Base sans ensureId, mémoïsée par jour calendaire.
     *
     * @return list<array{id: string, offset: int, label: string}>
     */
    private static function sortedByOffset(): array
    {
        $day = date('Y-m-d');
        if (self::$cache !== null && self::$cache['day'] === $day) {
            return self::$cache['list'];
        }

        // Un seul instant partagé : getOffset() ne dépend que de l'instant absolu,
        // pas du fuseau de l'objet DateTimeImmutable → inutile d'en créer un par id.
        $now  = new DateTimeImmutable('now');
        $list = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $offset = (new DateTimeZone($id))->getOffset($now);
            $list[] = ['id' => $id, 'offset' => $offset, 'label' => self::label($id, $offset)];
        }
        usort($list, self::compare(...));

        self::$cache = ['day' => $day, 'list' => $list];

        return $list;
    }

    /**
     * @param array{id: string, offset: int, label: string} $a
     * @param array{id: string, offset: int, label: string} $b
     */
    private static function compare(array $a, array $b): int
    {
        return [$a['offset'], $a['id']] <=> [$b['offset'], $b['id']];
    }

    private static function label(string $id, int $offset): string
    {
        $sign    = $offset >= 0 ? '+' : '-';
        $abs     = abs($offset);
        $hours   = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);
        $utc     = 'UTC' . $sign . $hours . ($minutes > 0 ? ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');

        return $utc . ' - ' . $id;
    }

    private static function isValid(string $id): bool
    {
        try {
            new DateTimeZone($id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
