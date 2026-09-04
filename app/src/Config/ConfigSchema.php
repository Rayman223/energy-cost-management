<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Schéma déclaratif de app/config/config.php, à lire en regard de
 * config.example.php : c'est son unique raison d'être. Chaque section y décrit
 * ses clés connues, ses sentinelles (`change_me…`) et sa nature (globale au site
 * vs par-utilisateur, cf. #153).
 *
 * Forme d'un nœud (toutes les clés sont optionnelles sauf mention) :
 *   - 'optional'      bool   La section peut manquer sans ERROR (défaut true ;
 *                            seule `database` est à false → ERROR si absente).
 *   - 'requiredKeys'  list   Clés dont l'absence rend la section incomplète (ERROR).
 *   - 'enabledDefault' bool  Défaut du drapeau `enabled` de CE nœud, utilisé pour
 *                            décider si ses sentinelles sont « actives » (le défaut
 *                            diffère par section : dynamic_prices=false, web_security=true).
 *   - 'children'      map    Clés connues → sous-nœuds (validation récursive +
 *                            détection de clé inconnue).
 *   - 'map'           bool   Clés libres (oidc.providers.*) :
 *                            pas de détection de clé inconnue, mais scan récursif
 *                            des sentinelles.
 *   - 'sentinel'      bool   Cette feuille scalaire doit être comparée aux sentinelles.
 *   - 'legacyFlatKeys' list  Clés supplémentaires tolérées (forme plate legacy OIDC).
 *   - 'moved'         string La clé a été déplacée (P3) : sa présence émet un WARNING
 *                            portant ce message d'instruction.
 *   - 'absentHint'    string Complément de message quand la section optionnelle manque.
 *
 * @phpstan-type Node array<string, mixed>
 */
final class ConfigSchema
{
    /** Valeurs sentinelles laissées par le template et à remplacer avant la prod. */
    public const SENTINELS = ['change_me', 'change_me_now'];

    /**
     * Racine du schéma : un nœud « section » dont les enfants sont les sections
     * de premier niveau du fichier de config.
     *
     * @return array<string, mixed>
     */
    public static function root(): array
    {
        return [
            'children' => [
                'database' => [
                    'optional'     => false,
                    'requiredKeys' => ['host', 'port', 'name', 'user', 'password'],
                    'children'     => [
                        'host'     => [],
                        'port'     => [],
                        'name'     => [],
                        'user'     => [],
                        'password' => ['sentinel' => true],
                        'charset'  => [],
                    ],
                ],

                'dynamic_prices' => [
                    // Absent ⇒ off (CostCalculationService, cron_dynamic_prices : ?? false).
                    'enabledDefault' => false,
                    'absentHint'     => 'tarif dynamique OFF',
                    'children'       => [
                        'enabled'                 => [],
                        'provider'                => [],
                        'api_url'                 => [],
                        'security_token'          => ['sentinel' => true],
                        'bidding_zone'            => [],
                        'timeout'                 => [],
                        // Sortis de la config globale (#153, P3). La TVA a ensuite quitté
                        // le profil pour la grille tarifaire, seule source (#232) ;
                        // la marge, elle, reste au profil comme repli (#228).
                        // ATTENTION à l'unité : la TVA passe de la fraction (0.21) au
                        // pourcentage (21.00).
                        'vat_rate' => [
                            'moved' => 'déplacé vers la grille tarifaire — reportez la valeur dans /tariffs (champ TVA de la grille). '
                                . "ATTENTION : l'unité passe de la fraction (0.21) au pourcentage (21.00).",
                        ],
                        'supplier_markup_per_kwh' => [
                            'moved' => 'déplacé vers le profil utilisateur — reportez la valeur dans /account.',
                        ],
                    ],
                ],

                'web_security' => [
                    'enabledDefault' => true,
                    'children'       => [
                        'enabled'       => [],
                        'allowed_ips'   => [],
                        'trusted_hosts' => [],
                        'basic_auth'    => [
                            'enabledDefault' => true,
                            'children'       => [
                                'enabled'  => [],
                                'username' => [],
                                'password' => ['sentinel' => true],
                            ],
                        ],
                    ],
                ],

                'oidc' => [
                    // Absent ⇒ Basic Auth seule. Forme plate legacy tolérée
                    // (issuer/client_id directement sous 'oidc', cf. OidcClientFactory).
                    'enabledDefault' => false,
                    'absentHint'     => 'Basic Auth seule',
                    'legacyFlatKeys' => ['issuer', 'client_id', 'client_secret', 'redirect_uri', 'scopes', 'label'],
                    'children'       => [
                        'enabled'   => [],
                        'providers' => ['map' => true],
                    ],
                ],

                'i18n' => [
                    'absentHint' => 'nl/de indisponibles (traductions présentes)',
                    'children'   => [
                        'default_locale' => [],
                        'available'      => [],
                    ],
                ],

                'api' => [
                    'absentHint' => 'rate-limit par défaut',
                    'children'   => [
                        'rate_limit_per_hour' => [],
                    ],
                ],

                'discord' => [
                    'children' => [
                        'invite_url' => [],
                    ],
                ],

                'donate' => [
                    // Lien de soutien au projet (#3) : absent ou vide ⇒ aucune icône
                    // dans l'en-tête (App\Support\DonateLink). Propre au déploiement.
                    'absentHint' => 'lien de don masqué',
                    'children'   => [
                        'url' => [],
                    ],
                ],

                'adsense' => [
                    // Publicité Google AdSense (#185) : absent ⇒ off, aucun script
                    // tiers chargé et CSP stricte préservée (App\Support\Adsense,
                    // App\Http\SecurityHeaders). Le client_id n'est pas une
                    // sentinelle : il est validé par format côté Adsense::clientId().
                    'enabledDefault' => false,
                    'absentHint'     => 'publicité désactivée',
                    'children'       => [
                        'enabled'   => [],
                        'client_id' => [],
                    ],
                ],

                'legal' => [
                    // Identité de l'éditeur affichée par /legal-notice et /privacy
                    // (App\Support\LegalIdentity). Propre à chaque déploiement :
                    // une clé manquante est signalée sur la page, pas en config.
                    'absentHint' => 'mentions légales incomplètes',
                    'children'   => [
                        'publisher'     => [],
                        'address'       => [],
                        'contact_email' => [],
                        'host'          => [],
                        'jurisdiction'  => [],
                    ],
                ],

                // Fuseau applicatif : doit rester UTC. Il alimente
                // date_default_timezone_set() au bootstrap, donc l'interprétation
                // de toute date construite sans fuseau explicite ; une valeur
                // non-UTC décalait les bornes tarifaires (#16).
                'timezone' => ['valueCheck' => 'timezone', 'expected' => 'UTC'],
            ],
        ];
    }
}
