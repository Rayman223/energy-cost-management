<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Publicité Google AdSense, pilotée par `config.php` (`adsense.*`). Frère de
 * {@see DiscordLink} et {@see DynamicPricing} : source unique du prédicat,
 * réutilisée par les pages (chargement du script dans `<head>`), par
 * {@see \App\Http\SecurityHeaders} (élargissement conditionnel de la CSP aux
 * origines Google) et par la page /cookies (bloc publicité rendu seulement si
 * la régie est réellement active). Voir #185.
 *
 * Format « Auto ads » : un unique script asynchrone suffit, Google choisit et
 * place les emplacements. Aucun `<script>` inline n'est donc nécessaire, ce qui
 * reste compatible avec la CSP stricte du projet (cf. SecurityHeaders).
 *
 * Le consentement RGPD n'est pas géré ici : il est délégué au CMP certifié
 * IAB TCF de Google (console AdSense → « Confidentialité et messages »).
 */
final class Adsense
{
    /**
     * Forme d'un identifiant éditeur AdSense : `ca-pub-` suivi de chiffres
     * (16 en pratique ; fourchette large pour ne pas rejeter un format futur).
     */
    private const CLIENT_ID_PATTERN = '/^ca-pub-\d{10,25}$/';

    /**
     * Renvoie l'identifiant éditeur si la régie est activée ET l'identifiant
     * exploitable, sinon null (⇒ aucun script tiers chargé, CSP inchangée).
     *
     * Le format est validé strictement : cette valeur part dans l'URL du script
     * tiers, la config ne doit pas pouvoir y injecter autre chose.
     *
     * @param array<string, mixed> $config
     */
    public static function clientId(array $config): ?string
    {
        $adsense = $config['adsense'] ?? [];
        if (!is_array($adsense)) {
            return null;
        }

        if (($adsense['enabled'] ?? false) !== true) {
            return null;
        }

        $clientId = $adsense['client_id'] ?? '';
        if (!is_string($clientId)) {
            return null;
        }

        $clientId = trim($clientId);

        return preg_match(self::CLIENT_ID_PATTERN, $clientId) === 1 ? $clientId : null;
    }

    /**
     * Vrai quand des annonces sont réellement diffusées (identifiant éditeur
     * valide et régie activée).
     *
     * @param array<string, mixed> $config
     */
    public static function isEnabled(array $config): bool
    {
        return self::clientId($config) !== null;
    }
}
