<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\Battery;

/**
 * Accès au parc de batteries de l'utilisateur courant (#26).
 * Seam de test des services de bilan, sans base de données.
 *
 * `user_id` n'apparaît dans aucune signature : il est injecté une fois dans
 * l'implémentation concrète et appliqué à CHAQUE requête — y compris à l'UPDATE
 * et au DELETE, pour qu'un identifiant deviné ne touche pas la ligne d'autrui.
 *
 * @see \App\Repository\BatteryRepository implémentation concrète (PDO/MySQL).
 */
interface BatteryRepositoryInterface
{
    /**
     * Parc complet, de la mise en service la plus récente à la plus ancienne.
     *
     * @return list<Battery>
     */
    public function listAll(): array;

    /** Une batterie du parc, ou null si l'identifiant est inconnu ou étranger. */
    public function find(int $id): ?Battery;

    /**
     * Cette batterie existe-t-elle ET appartient-elle à l'utilisateur courant ?
     *
     * Distinct de `rowCount()` après écriture : sans `MYSQL_ATTR_FOUND_ROWS`, un
     * UPDATE qui ne change aucune valeur rapporte zéro ligne affectée bien que la
     * cible existe — s'y fier confondrait « batterie introuvable » et
     * « réenregistrée à l'identique ».
     */
    public function owns(int $id): bool;

    /**
     * Insère une batterie et renvoie son identifiant.
     *
     * `$battery->id` est IGNORÉ : l'identifiant est attribué par la base. Passer
     * l'objet complet plutôt que quatorze paramètres positionnels évite qu'un
     * champ optionnel glisse d'une position à l'autre au fil des évolutions.
     */
    public function insert(Battery $battery): int;

    /** Met à jour la batterie `$id` ; `$battery->id` est ignoré, seul `$id` cible. */
    public function update(int $id, Battery $battery): void;

    /** Supprime la batterie et, par cascade FK, tous ses relevés. */
    public function delete(int $id): void;
}
