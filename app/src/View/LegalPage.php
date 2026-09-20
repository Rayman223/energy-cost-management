<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\Locale;
use App\Seo\PageMeta;
use App\Support\Adsense;
use App\Support\LegalIdentity;

/**
 * Assemble les pages légales publiques (#185), toutes rendues par le même
 * template `legal` : confidentialité, conditions d'utilisation, cookies et
 * mentions légales.
 *
 * Ces quatre pages partagent exactement le même câblage (locale, langues
 * disponibles, identité de l'éditeur, état de la publicité) ; il vit ici plutôt
 * que dupliqué dans chaque script de route, qui se réduit alors au bootstrap
 * et au rendu.
 */
final class LegalPage
{
    /** Pages reconnues — une route publique par valeur (cf. app/public/index.php). */
    public const PAGES = ['privacy', 'terms', 'cookies', 'legal-notice'];

    /**
     * @param array<string, mixed> $config
     * @param string               $page Une valeur de {@see self::PAGES}
     */
    public static function render(array $config, string $page): string
    {
        $templateDir = \dirname(__DIR__, 2) . '/templates';
        $locale      = Locale::resolve($config, null);

        $view = ViewFactory::create(
            $templateDir,
            $locale,
            (string) ($config['i18n']['default_locale'] ?? 'fr'),
        );

        return $view->render('legal', [
            'page'      => $page,
            'available' => Locale::available($config),
            'legal'     => LegalIdentity::from($config),
            // La page /cookies ne décrit les cookies publicitaires que si la
            // régie est réellement active : une politique ne doit pas annoncer
            // des traitements qui n'ont pas lieu.
            'adsEnabled'    => Adsense::isEnabled($config),
            'adsenseClient' => Adsense::clientId($config),
            // Pages publiques et stables : indexables, une description par page
            // (#84). La clé suit le nom de la page, garanti dans self::PAGES.
            'meta'          => PageMeta::indexable(
                $config,
                $page,
                $locale,
                $view->t('seo.description.' . $page),
            ),
        ]);
    }
}
