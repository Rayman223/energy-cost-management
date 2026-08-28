<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Infrastructure\HttpClient;

/**
 * Connexion « Se connecter avec GitHub » (Issue #24).
 *
 * GitHub n'est **pas** un fournisseur OpenID Connect pour la connexion des
 * utilisateurs : il ne publie aucun `/.well-known/openid-configuration` (404) et
 * son token endpoint ne renvoie pas d'`id_token`. La lib jumbojett, qui exige
 * l'un et l'autre, est donc inutilisable ici — d'où ce connecteur OAuth 2.0
 * dédié (namespace `OAuth` et non `Oidc`, précisément parce que ce n'en est pas).
 *
 * Il se greffe sur la route d'authentification existante : même `redirect_uri`
 * (`/auth/login`), même résolution de fournisseur par `?provider=`, et il rend au
 * final le couple `(sub, displayName)` attendu par {@see \App\Security\AccountProvisioner}
 * et {@see \App\Security\IdentityLinker}. L'issuer conventionnel `https://github.com`
 * du bloc de config est la valeur stockée en base (`users.oidc_iss`).
 *
 * ## Sécurité
 *
 * - Les OAuth Apps GitHub **n'implémentent pas PKCE** : le `state` aléatoire posé
 *   en session est la seule protection contre la fixation de code, il est donc
 *   obligatoire et vérifié en temps constant.
 * - Le `sub` est l'`id` numérique du compte, **jamais** le `login` : un login est
 *   renommable, et le réutiliser laisserait un tiers récupérer un compte abandonné.
 * - Le jeton d'accès sert le temps d'un appel à `/user` : il n'est ni stocké ni
 *   journalisé.
 */
final class GithubOAuthClient
{
    /** Clé de session portant le `state` du flux en cours. */
    public const STATE_KEY = 'auth_github_state';

    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL     = 'https://github.com/login/oauth/access_token';
    private const USER_URL      = 'https://api.github.com/user';

    public function __construct(private readonly HttpClient $http = new HttpClient())
    {
    }

    /**
     * Le bloc de fournisseur désigne-t-il GitHub ? Comparaison sur l'hôte de
     * l'issuer, comme {@see \App\Security\Oidc\OidcClientFactory::isDiscord()} :
     * un `https://notgithub.com` ne doit pas hériter du flux GitHub.
     *
     * GitHub Enterprise Server (hôte propre à chaque organisation) n'est pas
     * couvert : il faudrait au minimum rendre les trois endpoints configurables.
     *
     * @param array<string, mixed> $config Bloc d'un fournisseur.
     */
    public static function supports(array $config): bool
    {
        $host = parse_url((string) ($config['issuer'] ?? ''), PHP_URL_HOST);

        return is_string($host) && ($host === 'github.com' || str_ends_with($host, '.github.com'));
    }

    /**
     * URL de la demande d'autorisation. Le `scope` est **omis** quand la config
     * n'en demande pas : un jeton sans scope suffit à lire `id`, `login` et `name`
     * publics sur `/user`, et demander `read:user` donnerait accès au profil privé
     * sans rien apporter. Aucun e-mail n'est demandé.
     *
     * @param array<string, mixed> $config Bloc d'un fournisseur.
     */
    public static function authorizationUrl(array $config, string $state, string $redirectUri): string
    {
        $params = [
            'client_id'    => (string) ($config['client_id'] ?? ''),
            'redirect_uri' => $redirectUri,
            'state'        => $state,
        ];

        $scopes = self::scopes($config);
        if ($scopes !== []) {
            $params['scope'] = implode(' ', $scopes);
        }

        return self::AUTHORIZE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Jeton d'accès extrait de la réponse du token endpoint (demandée en JSON).
     * GitHub répond **200 avec un corps d'erreur** sur un code invalide ou des
     * identifiants erronés : c'est le corps qui fait foi, pas le statut HTTP.
     *
     * @throws \RuntimeException Réponse illisible, erreur GitHub, jeton absent.
     */
    public static function accessTokenFromResponse(string $body): string
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('GitHub token endpoint: réponse illisible.');
        }

        $error = $payload['error'] ?? null;
        if (is_string($error) && $error !== '') {
            // Le message GitHub (bad_verification_code, incorrect_client_credentials…)
            // porte tout le diagnostic ; aucun secret n'y figure.
            throw new \RuntimeException('GitHub token endpoint: ' . $error);
        }

        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('GitHub token endpoint: access_token manquant.');
        }

        return $token;
    }

    /**
     * Identité dérivée de la réponse de `https://api.github.com/user`.
     *
     * @return array{sub: string, name: string} `name` peut être vide (nom d'affichage optionnel).
     * @throws \RuntimeException Réponse illisible ou identifiant de compte absent.
     */
    public static function identityFromUserPayload(string $body): array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('GitHub /user: réponse illisible.');
        }

        // `id` est un entier en JSON, mais on tolère sa forme chaîne : la valeur
        // stockée est de toute façon une chaîne (users.oidc_sub).
        $id = $payload['id'] ?? null;
        $sub = is_int($id) || (is_string($id) && $id !== '') ? (string) $id : '';
        if ($sub === '' || $sub === '0') {
            throw new \RuntimeException('GitHub /user: identifiant de compte manquant.');
        }

        // `name` est le nom affiché sur le profil, souvent nul ; `login` est
        // toujours présent et fait un repli lisible.
        $name = $payload['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $login = $payload['login'] ?? null;
            $name = is_string($login) ? trim($login) : '';
        }

        return ['sub' => $sub, 'name' => $name];
    }

    /**
     * Un tour du flux d'autorisation.
     *
     * - **Initiation** (`$isCallback = false`) : tire un `state`, le mémorise en
     *   session et redirige vers GitHub. Ne rend jamais la main.
     * - **Callback** : vérifie le `state`, échange le code contre un jeton, puis
     *   lit l'identité. Toute anomalie lève — la route journalise la cause
     *   ({@see \App\Security\Oidc\OidcAuthFailure}) et affiche la page d'erreur.
     *
     * @param array<string, mixed> $config Bloc du fournisseur GitHub.
     * @return array{sub: string, name: string}
     * @throws \Throwable
     */
    public function authenticate(array $config, bool $isCallback, string $redirectUri): array
    {
        if ($isCallback === false) {
            $state = bin2hex(random_bytes(16));
            $_SESSION[self::STATE_KEY] = $state;

            header('Location: ' . self::authorizationUrl($config, $state, $redirectUri), true, 302);
            exit;
        }

        // Le state est à usage unique : l'oublier avant toute autre vérification
        // interdit de rejouer un callback.
        $expected = $_SESSION[self::STATE_KEY] ?? null;
        unset($_SESSION[self::STATE_KEY]);

        $error = $_GET['error'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new \RuntimeException('GitHub authorize: ' . $error);
        }

        $state = $_GET['state'] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($state) || !hash_equals($expected, $state)) {
            throw new \RuntimeException('GitHub callback: state invalide.');
        }

        $code = $_GET['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new \RuntimeException('GitHub callback: code manquant.');
        }

        return $this->identity($this->exchangeCode($config, $code, $redirectUri));
    }

    /**
     * Échange du code d'autorisation contre un jeton d'accès. Le corps est
     * `application/x-www-form-urlencoded` (RFC 6749) et la réponse demandée en
     * JSON — sans l'en-tête `Accept`, GitHub répond en `application/x-www-form-urlencoded`.
     *
     * @param array<string, mixed> $config Bloc du fournisseur GitHub.
     * @throws \RuntimeException
     */
    private function exchangeCode(array $config, string $code, string $redirectUri): string
    {
        $response = $this->http->postForm(
            self::TOKEN_URL,
            [
                'client_id'     => (string) ($config['client_id'] ?? ''),
                'client_secret' => (string) ($config['client_secret'] ?? ''),
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
            ],
            15,
            ['Accept' => 'application/json'],
        );

        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'GitHub token endpoint: HTTP ' . (string) ($response['status'] ?? 0)
                . ' ' . (string) ($response['error'] ?? '')
            );
        }

        return self::accessTokenFromResponse((string) ($response['body'] ?? ''));
    }

    /**
     * Lecture du compte associé au jeton.
     *
     * @return array{sub: string, name: string}
     * @throws \RuntimeException
     */
    private function identity(string $accessToken): array
    {
        $response = $this->http->get(self::USER_URL, 15, [
            'Authorization'        => 'Bearer ' . $accessToken,
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);

        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException(
                'GitHub /user: HTTP ' . (string) ($response['status'] ?? 0)
                . ' ' . (string) ($response['error'] ?? '')
            );
        }

        return self::identityFromUserPayload((string) ($response['body'] ?? ''));
    }

    /**
     * Scopes demandés, tels que configurés. Aucun par défaut (cf. {@see self::authorizationUrl()}).
     *
     * @param array<string, mixed> $config Bloc d'un fournisseur.
     * @return list<string>
     */
    private static function scopes(array $config): array
    {
        $scopes = $config['scopes'] ?? null;
        if (!is_array($scopes)) {
            return [];
        }

        $clean = [];
        foreach ($scopes as $scope) {
            // « openid » n'a aucun sens côté GitHub : le laisser passer ferait
            // échouer la demande en scope inconnu.
            if (is_string($scope) && $scope !== '' && $scope !== 'openid') {
                $clean[] = $scope;
            }
        }

        return $clean;
    }
}
