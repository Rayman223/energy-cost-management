<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plafonds anti-abus pilotés par `config.php` (`limits.*`), source unique du
 * chiffre pour toutes les gardes qui s'y réfèrent (#55). Frère de
 * {@see Adsense} et {@see DiscordLink} : la config est lue ici, une seule fois,
 * plutôt que déréférencée à la main sur chaque site d'appel.
 *
 * Ces plafonds ne décrivent pas une limite fonctionnelle — rien n'empêche
 * conceptuellement un foyer d'avoir six compteurs d'eau — mais une limite
 * d'exploitation : sans elle, un compte peut créer des lignes sans fin, et
 * chaque compteur supplémentaire alourdit toutes les agrégations de rapport.
 * D'où la valeur par défaut confortable plutôt que serrée.
 */
final class Limits
{
    /** Compteurs par énergie et par utilisateur, à défaut de configuration. */
    public const DEFAULT_METERS_PER_ENERGY = 5;

    /**
     * Bornes du réglage lui-même. En dessous de 1, plus personne ne pourrait
     * créer le moindre compteur — un zéro (ou une chaîne vide lue comme zéro)
     * rendrait l'application inutilisable sans le moindre message. Au-dessus de
     * 50, le coût des agrégations multi-compteurs cesse d'être négligeable
     * (cf. risque n° 7 du plan #55).
     */
    private const MIN = 1;

    private const MAX = 50;

    /**
     * Nombre de compteurs qu'un utilisateur peut posséder POUR UNE ÉNERGIE
     * DONNÉE : le plafond est par énergie, pas global — trois compteurs d'eau ne
     * doivent pas empêcher d'en déclarer un quatrième d'électricité.
     *
     * Sert aussi de plafond au parc de batteries, qui pose exactement le même
     * problème d'exploitation pour la même raison. Un second réglage n'aurait
     * fait qu'ajouter une clé à oublier de renseigner.
     *
     * Toute valeur inexploitable — absente, non numérique, hors bornes — retombe
     * sur un plafond valide : un refus de créer motivé par une faute de frappe
     * dans `config.php` serait indéchiffrable côté utilisateur.
     *
     * @param array<string, mixed> $config
     */
    public static function metersPerEnergy(array $config): int
    {
        $limits = $config['limits'] ?? [];
        if (!is_array($limits)) {
            return self::DEFAULT_METERS_PER_ENERGY;
        }

        $raw = $limits['meters_per_energy'] ?? null;
        if (!is_int($raw) && !(is_string($raw) && $raw !== '')) {
            return self::DEFAULT_METERS_PER_ENERGY;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            return self::DEFAULT_METERS_PER_ENERGY;
        }

        return max(self::MIN, min(self::MAX, $value));
    }
}
