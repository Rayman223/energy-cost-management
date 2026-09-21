<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\Locale;
use App\Seo\PageMeta;
use App\Support\Adsense;
use App\Support\DiscordLink;
use App\Support\DonateLink;

/**
 * Assemble les pages de guides (#85) : l'index `/guides` et chaque guide.
 *
 * Même rôle que {@see LegalPage} pour les pages légales — le câblage commun
 * (locale, langues disponibles, publicité, référencement) vit ici, et chaque
 * script de route se réduit au bootstrap et au rendu.
 *
 * Ces pages existent pour une raison précise : le site n'exposait que six URLs
 * indexables, dont quatre pages légales, ce qu'AdSense a qualifié de « contenu
 * à faible valeur informative ». Elles sont publiques et indexables sans
 * condition : leur contenu ne dépend ni de la base ni d'une session.
 */
final class GuidePage
{
    /**
     * @param array<string, mixed> $config
     */
    public static function index(array $config): string
    {
        [$view, $locale, $fallback] = self::view($config);

        return $view->render('guides', [
            'guides'        => GuideContent::all($locale, $fallback),
            'available'     => Locale::available($config),
            'discordUrl'    => DiscordLink::inviteUrl($config),
            'donateUrl'     => DonateLink::url($config),
            'adsenseClient' => Adsense::clientId($config),
            'meta'          => PageMeta::indexable(
                $config,
                'guides',
                $locale,
                $view->t('guides.description'),
            ),
        ]);
    }

    /**
     * Un guide. Slug inconnu ⇒ null : la route répond alors 404 plutôt que de
     * rendre une page vide.
     *
     * @param array<string, mixed> $config
     */
    public static function render(array $config, string $slug): ?string
    {
        [$view, $locale, $fallback] = self::view($config);

        $guide = GuideContent::load($slug, $locale, $fallback);
        if ($guide === null) {
            return null;
        }

        return $view->render('guide', [
            'guide'         => $guide,
            'available'     => Locale::available($config),
            'discordUrl'    => DiscordLink::inviteUrl($config),
            'donateUrl'     => DonateLink::url($config),
            'adsenseClient' => Adsense::clientId($config),
            'meta'          => PageMeta::indexable(
                $config,
                'guides/' . $guide['slug'],
                $locale,
                $guide['description'],
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: View, 1: string, 2: string}
     */
    private static function view(array $config): array
    {
        $locale   = Locale::resolve($config, null);
        $fallback = (string) ($config['i18n']['default_locale'] ?? 'fr');

        return [
            ViewFactory::create(\dirname(__DIR__, 2) . '/templates', $locale, $fallback),
            $locale,
            $fallback,
        ];
    }
}
