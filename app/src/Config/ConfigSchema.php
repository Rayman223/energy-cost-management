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
 *                            diffère par section : dynamic_prices=false, energyid=true).
 *   - 'children'      map    Clés connues → sous-nœuds (validation récursive +
 *                            détection de clé inconnue).
 *   - 'map'           bool   Clés libres (oidc.providers.*, energyid.device.*) :
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

                'energyid' => [
                    // Kill-switch global lu par cron_daily_webhook.php (?? true).
                    'enabledDefault' => true,
                    'absentHint'     => 'push EnergyID indisponible',
                    'children'       => [
                        'enabled'             => [],
                        'provisioning_key'    => ['sentinel' => true],
                        'provisioning_secret' => ['sentinel' => true],
                        'timeout'             => [],
                        'device'              => ['map' => true],
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
                        'supplier_markup_per_kwh' => [],
                        'vat_rate'                => [],
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

                'timezone' => [],
            ],
        ];
    }
}
