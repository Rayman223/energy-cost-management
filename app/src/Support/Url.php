<?php

declare(strict_types=1);

namespace App\Support;

use App\Security\WebAccessGuard;

/**
 * Génère les URL internes (liens de pages) sans extension `.php`.
 *
 * Depuis le passage à un front controller unique (`public/index.php`), les pages
 * sont exposées par des chemins propres (`/account`, `/tariffs`, `/api`…). Ce
 * helper préfixe le chemin par la racine de l'application ({@see WebAccessGuard::appRootPath()})
 * afin de rester correct quel que soit le préfixe de déploiement (installation en
 * sous-répertoire). Pendant de {@see Assets::url()} pour les liens de navigation.
 */
final class Url
{
    /**
     * URL interne pour un chemin de page (ex. "account", "auth/login", "" pour l'accueil).
     *
     * Exemples : `Url::to('account')` → `/account` (ou `/sous-rep/account`),
     * `Url::to('')` → `/`.
     */
    public static function to(string $path): string
    {
        $path = ltrim($path, '/');
        $root = WebAccessGuard::appRootPath();

        return $path === '' ? ($root . '/') : ($root . '/' . $path);
    }
}
