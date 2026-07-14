<?php

declare(strict_types=1);

namespace App\Security\Oidc;

use App\Infrastructure\HttpClient;

/**
 * Cache fichier de la découverte OpenID Connect (document
 * `/.well-known/openid-configuration`) par issuer.
 *
 * Sans ce cache, la lib jumbojett re-télécharge le well-known à chaque initiation
 * ET à chaque callback (aller-retour réseau synchrone, dépendant de la latence de
 * l'IdP). On mémorise le document par issuer (TTL 1 h) pour l'injecter dans le
 * client via `providerConfigParam()` — les endpoints (authorization/token/jwks_uri)
 * sont alors résolus sans requête réseau.
 *
 * ## Intégrité (anti-empoisonnement)
 *
 * Le document mis en cache dicte les endpoints de sécurité (`jwks_uri`,
 * `token_endpoint`, `authorization_endpoint`). Un fichier falsifié par un tiers
 * permettrait une usurpation (clés de signature ou endpoint de jetons pirates).
 * Défenses :
 *  - répertoire **privé** `0700` sous {@see sys_get_temp_dir()}, **propriété du
 *    process** ; si l'on ne peut pas le garantir (déjà présent avec un autre
 *    propriétaire ou des droits laxistes), on **refuse** le cache et on retombe
 *    sur la découverte à la volée par la lib (comportement historique, sûr) ;
 *  - fichiers écrits en `0600` (atomique via rename), et **propriété du process**
 *    vérifiée à la lecture.
 *
 * ## Tolérance aux pannes
 *
 * Toute défaillance (réseau, JSON invalide, écriture impossible, cache corrompu,
 * expiré ou non fiable) renvoie `null` → l'appelant retombe sur le comportement
 * historique.
 */
final class OidcDiscoveryCache
{
    /** Durée de validité d'une entrée de cache (secondes). */
    private const TTL = 3600;

    /** Sous-répertoire privé dédié dans le répertoire temporaire. */
    private const DIR = 'mec_oidc_cache';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /**
     * Document de découverte de l'issuer (depuis le cache s'il est frais, sinon
     * téléchargé puis mémorisé). `null` en cas d'échec — jamais d'exception.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $issuer): ?array
    {
        if ($issuer === '') {
            return null;
        }

        $dir = $this->secureDir();
        if ($dir === null) {
            // Répertoire de cache non fiable : ne pas risquer une découverte
            // empoisonnée → découverte à la volée par la lib.
            return null;
        }
        $path = $dir . '/' . hash('sha256', $issuer) . '.json';

        $fresh = $this->readFresh($path);
        if ($fresh !== null) {
            return $fresh;
        }

        $discovery = $this->fetch($issuer);
        if ($discovery === null) {
            return null;
        }

        $this->write($path, $discovery);

        return $discovery;
    }

    /**
     * Supprime l'entrée de cache d'un issuer. Appelé après un échec
     * d'authentification pour forcer une re-découverte (endpoints déplacés côté
     * IdP → ne pas rester bloqué sur des valeurs périmées jusqu'au TTL).
     */
    public function invalidate(string $issuer): void
    {
        if ($issuer === '') {
            return;
        }

        @unlink(sys_get_temp_dir() . '/' . self::DIR . '/' . hash('sha256', $issuer) . '.json');
    }

    /**
     * Répertoire de cache privé (`0700`, propriété du process), créé au besoin.
     * `null` si l'on ne peut pas garantir qu'un tiers n'y a pas déposé de fichier.
     */
    private function secureDir(): ?string
    {
        $dir = sys_get_temp_dir() . '/' . self::DIR;

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir)) {
            return null;
        }

        // Le répertoire doit appartenir au process courant et n'accorder aucun
        // droit groupe/autres — sinon un tiers pourrait y pré-déposer une
        // découverte malveillante avant nous.
        $uid = @getmyuid();
        if ($uid !== false && @fileowner($dir) !== $uid) {
            return null;
        }
        $perms = @fileperms($dir);
        if ($perms !== false && ($perms & 0o077) !== 0) {
            return null;
        }

        return $dir;
    }

    /**
     * Entrée de cache si présente, appartenant au process, non expirée et bien
     * formée ; `null` sinon.
     *
     * @return array<string, mixed>|null
     */
    private function readFresh(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        // Un fichier qui ne nous appartient pas est suspect (pré-déposé) : ignoré.
        $uid = @getmyuid();
        if ($uid !== false && @fileowner($path) !== $uid) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $fetchedAt = $decoded['fetched_at'] ?? null;
        $discovery = $decoded['discovery'] ?? null;
        if (!is_int($fetchedAt) || !is_array($discovery)) {
            return null;
        }
        if (time() - $fetchedAt >= self::TTL) {
            return null;
        }

        /** @var array<string, mixed> $discovery */
        return $discovery;
    }

    /**
     * Télécharge le document de découverte ; `null` si indisponible ou invalide.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $issuer): ?array
    {
        $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';

        try {
            $response = $this->http->get($url, 10);
        } catch (\Throwable) {
            return null;
        }

        if (($response['ok'] ?? false) !== true) {
            return null;
        }

        $body = $response['body'] ?? '';
        $decoded = is_string($body) ? json_decode($body, true) : null;

        /** @var array<string, mixed>|null $decoded */
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /**
     * Écrit l'entrée de cache en `0600`, de façon atomique (temp + rename) pour
     * éviter toute lecture partielle. Best-effort : un échec est ignoré.
     *
     * @param array<string, mixed> $discovery
     */
    private function write(string $path, array $discovery): void
    {
        $payload = json_encode(
            ['fetched_at' => time(), 'discovery' => $discovery],
            JSON_UNESCAPED_SLASHES,
        );
        if ($payload === false) {
            return;
        }

        try {
            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        } catch (\Throwable) {
            return;
        }

        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}
