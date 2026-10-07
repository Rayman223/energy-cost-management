<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Génère les URL d'assets statiques avec cache-busting automatique.
 *
 * Le suffixe `?v=<mtime>` force le rechargement par le navigateur dès qu'un
 * fichier change, sans build tooling : la version est la date de dernière
 * modification du fichier sur le disque. Les chemins partent de la racine de
 * l'application ({@see Url::to()}), préfixe de déploiement compris : relatifs à la
 * page, ils casseraient sur une route imbriquée (`/admin/load-profiles`,
 * `/guides/…`), où « assets/… » se résoudrait en « /admin/assets/… » (404).
 */
final class Assets
{
    /** Répertoire racine web (app/public). */
    private static function publicDir(): string
    {
        return \dirname(__DIR__, 2) . '/public';
    }

    /**
     * URL d'un asset relatif à app/public (ex. "assets/css/dashboard.css"),
     * suffixée par sa version (mtime) si le fichier existe.
     */
    public static function url(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $fsPath       = self::publicDir() . '/' . $relativePath;

        $version = is_file($fsPath) ? (string) filemtime($fsPath) : null;

        $url = Url::to($relativePath);

        return $version !== null ? $url . '?v=' . $version : $url;
    }
}
