<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Identité de l'éditeur du site, exigée par les mentions légales (directive
 * e-commerce 2000/31/CE) et par le RGPD (responsable du traitement, art. 13).
 * Lue dans `config.php` (`legal.*`) car elle est propre à chaque déploiement :
 * rien de nominatif n'est versionné. Voir #185.
 *
 * Toutes les clés sont optionnelles et normalisées ici : une valeur absente,
 * vide ou d'un type inattendu ressort à `null`, ce qui permet au template de
 * signaler visiblement l'information manquante plutôt que d'afficher un blanc.
 */
final class LegalIdentity
{
    /** Clés reconnues de la section `legal`, dans l'ordre d'affichage. */
    public const FIELDS = ['publisher', 'address', 'contact_email', 'host', 'jurisdiction'];

    /**
     * Normalise la section `legal` de la configuration.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, ?string> Clés de {@see self::FIELDS}, valeurs nettoyées ou null.
     */
    public static function from(array $config): array
    {
        $legal = $config['legal'] ?? [];
        if (!is_array($legal)) {
            $legal = [];
        }

        $identity = [];
        foreach (self::FIELDS as $field) {
            $identity[$field] = self::scalar($legal[$field] ?? null);
        }

        // L'adresse de contact part dans un `mailto:` : un format invalide est
        // écarté plutôt que rendu tel quel.
        if ($identity['contact_email'] !== null && filter_var($identity['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
            $identity['contact_email'] = null;
        }

        return $identity;
    }

    /**
     * Chaîne non vide après nettoyage, ou null.
     */
    private static function scalar(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
