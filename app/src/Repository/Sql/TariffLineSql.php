<?php

declare(strict_types=1);

namespace App\Repository\Sql;

use App\Domain\ComponentKind;
use App\Domain\TariffUnitRate;

/**
 * Fragments SQL dérivés des enums du domaine (#8).
 *
 * Les agrégats de /stats doivent appliquer, en SQL et sur des milliers de lignes,
 * exactement les règles que {@see TariffUnitRate} et {@see ComponentKind} portent
 * en PHP. Recopier ces règles dans un heredoc les ferait diverger au premier case
 * ajouté à l'enum : le nouveau kind vaudrait silencieusement zéro côté SQL et son
 * vrai poids côté PHP, et la comparaison « moi vs la moyenne » afficherait un
 * écart inexplicable.
 *
 * On génère donc les CASE depuis les enums. Effet de bord voulu : le test
 * d'exhaustivité de TariffUnitRate devient le garde-fou des deux chemins à la fois.
 *
 * SÉCURITÉ : aucune valeur interpolée ici ne vient d'une saisie. Les libellés
 * sortent de `ComponentKind::cases()` (littéraux du code) et les poids de
 * `TariffUnitRate::weight()` (float formaté). Il n'y a donc rien à lier — et rien
 * qu'un placeholder pourrait protéger, MySQL n'acceptant pas de paramètre à la
 * place d'un fragment d'expression.
 */
final class TariffLineSql
{
    /**
     * Expression du poids d'une ligne dans le tarif au kWh.
     *
     * Rend `CASE l.component_kind WHEN 'energy_flat' THEN 1.0000 … ELSE 0.0 END`.
     * Le `ELSE 0.0` couvre les valeurs présentes en base mais absentes de l'enum
     * (grille importée d'une version antérieure) : hors périmètre, poids nul.
     *
     * @param string $column Colonne SQL portant le kind, déjà qualifiée.
     */
    public static function perKwhWeightCase(string $column = 'l.component_kind'): string
    {
        $sql = 'CASE ' . $column;
        foreach (ComponentKind::cases() as $kind) {
            $sql .= sprintf(
                " WHEN '%s' THEN %s",
                $kind->value,
                number_format(TariffUnitRate::weight($kind), 4, '.', ''),
            );
        }

        return $sql . ' ELSE 0.0 END';
    }

    /**
     * Expression de la catégorie d'affichage d'une ligne.
     *
     * La colonne `category` prime quand elle est renseignée (elle seule porte
     * `distribution`, que les kinds ne distinguent pas de `taxes`) ; sinon on
     * retombe sur le groupe du kind, comme le fait `TariffLine::category()`.
     *
     * @param string $kindColumn     Colonne portant le kind.
     * @param string $categoryColumn Colonne portant la catégorie explicite.
     */
    public static function categoryCase(
        string $kindColumn = 'l.component_kind',
        string $categoryColumn = 'l.category',
    ): string {
        $sql = 'CASE ' . $kindColumn;
        foreach (ComponentKind::cases() as $kind) {
            $sql .= sprintf(" WHEN '%s' THEN '%s'", $kind->value, $kind->group());
        }
        $sql .= " ELSE 'taxes' END";

        return sprintf('COALESCE(NULLIF(%s, %s), %s)', $categoryColumn, "''", $sql);
    }

    /**
     * Liste SQL des kinds pesant réellement au kWh, pour un `IN (…)`.
     *
     * Permet d'écarter en amont les lignes de poids nul plutôt que de les
     * multiplier par zéro : moins de lignes agrégées, même résultat.
     */
    public static function perKwhKindList(): string
    {
        $values = [];
        foreach (ComponentKind::cases() as $kind) {
            if (TariffUnitRate::weight($kind) !== 0.0) {
                $values[] = "'" . $kind->value . "'";
            }
        }

        // Aucun kind pesant : liste impossible à satisfaire plutôt que `IN ()`,
        // syntaxiquement invalide en MySQL.
        return $values === [] ? "''" : implode(', ', $values);
    }
}
