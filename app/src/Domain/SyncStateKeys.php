<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Clés de flux pour `webhook_sync_state.source_name` (watermarks de sync).
 *
 * Ces valeurs sont persistées en base : elles ne doivent PAS changer sous peine
 * de perdre l'historique des envois. Partagées entre le service de sync
 * (module d'export) et les points de saisie/import qui rembobinent le watermark
 * ({@see \App\Http\Controller\MeterEntryController}, {@see \App\Service\Import\ImportRunner}).
 */
final class SyncStateKeys
{
    /** State keys électricité (import/injection jour+nuit, production solaire). */
    public const ELEC = [
        'prelevement-jour',
        'prelevement-nuit',
        'injection-jour',
        'injection-nuit',
        'production-solaire',
    ];

    public const GAS   = 'gas-index';
    public const WATER = 'water-index';

    private function __construct()
    {
    }
}
