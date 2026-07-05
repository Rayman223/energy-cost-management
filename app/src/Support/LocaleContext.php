<?php

declare(strict_types=1);

namespace App\Support;

use App\I18n\Locale;
use App\Repository\UserRepository;
use App\View\ViewFactory;
use App\View\View;

/**
 * Fabrique une {@see View} localisée pour un utilisateur web, en centralisant la
 * séquence dupliquée dans les contrôleurs (`index.php`, `tariffs.php`,
 * `account.php`, `admin.php`) : résolution de la locale (profil surchargé par
 * `?lang`), persistance du choix explicite dans le profil, puis construction de
 * la View configurée.
 */
final class LocaleContext
{
    /**
     * @param array<string, mixed> $config
     * @param ?string $profileLocale Locale enregistrée du profil (déjà lue par
     *                               l'appelant), ou null.
     */
    public static function viewFor(
        array $config,
        UserRepository $users,
        int $userId,
        ?string $profileLocale,
        string $templateDir,
    ): View {
        $locale = Locale::resolve($config, $profileLocale);

        // Persiste un switch explicite (?lang) valide, uniquement s'il diffère.
        $choice = Locale::explicitChoice($config);
        if ($choice !== null && $choice !== $profileLocale) {
            $users->setLocale($userId, $choice);
        }

        return ViewFactory::create($templateDir, $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));
    }
}
