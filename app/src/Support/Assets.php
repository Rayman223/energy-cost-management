<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Génère les URL d'assets statiques avec cache-busting automatique.
 *
 * Le suffixe `?v=<mtime>` force le rechargement par le navigateur dès qu'un
 * fichier change, sans build tooling : la version est la date de dernière
 * modification du fichier sur le disque. Les chemins sont relatifs à la page
 * appelante (les entrées vivent dans app/public/, les assets dans
 * app/public/assets/), ce qui reste correct quel que soit le préfixe de
 * déploiement (sous-répertoire).
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

        return $version !== null ? $relativePath . '?v=' . $version : $relativePath;
    }
}
