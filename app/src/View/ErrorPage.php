<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\Locale;

/**
 * Pages d'erreur HTML (403, 404…), rendues par le template `error`.
 *
 * Introduit pour le **404 du front controller** (#84) : jusqu'ici, une URL
 * inconnue répondait un `text/plain` « Not Found » — correct pour un moteur (le
 * code HTTP fait foi), mais une impasse pour un visiteur, sans le moindre lien
 * de retour.
 *
 * Réservé aux contextes **sans session** : la locale y est résolue depuis la
 * requête seule. Le 403 de `/admin` continue de rendre le template directement,
 * avec la vue déjà construite sur la locale du profil — plus précise que ce que
 * cette classe peut savoir.
 *
 * Aucune métadonnée de référencement n'est passée au `<head>` : le défaut
 * fermé du partial y répond `noindex, follow` ({@see \App\Seo\PageMeta}).
 */
final class ErrorPage
{
    /**
     * @param array<string, mixed> $config
     * @param int                  $code       Code HTTP affiché (403, 404…)
     * @param ?string              $messageKey Clé de traduction du message ; par défaut
     *                                         le libellé « page introuvable ».
     */
    public static function render(array $config, int $code, ?string $messageKey = null): string
    {
        $view = ViewFactory::create(
            \dirname(__DIR__, 2) . '/templates',
            Locale::resolve($config, null),
            (string) ($config['i18n']['default_locale'] ?? 'fr'),
        );

        return $view->render('error', [
            'code'    => $code,
            'message' => $view->t($messageKey ?? 'error.not_found'),
        ]);
    }
}
