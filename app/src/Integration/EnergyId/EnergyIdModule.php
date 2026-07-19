<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

use App\Infrastructure\HttpClient;
use App\Integration\ExportModuleInterface;
use App\Integration\IntegrationStatus;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use App\Support\Dates;
use DateTimeImmutable;
use PDO;

/**
 * Connecteur d'export EnergyID (BE/NL) : push quotidien des index vers le
 * service cloud via le protocole « Incoming Webhooks V2 ». Premier module du
 * système de connecteurs (#70) — reprend à l'identique la logique de l'ancien
 * cron dédié (provisioning /hello, sync élec/gaz/eau, claim au premier envoi).
 */
final class EnergyIdModule implements ExportModuleInterface
{
    private ?EnergyIdV2Client $client = null;

    /**
     * @param array<string, mixed> $config configuration globale de l'application
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function key(): string
    {
        return 'energyid';
    }

    public function isGloballyEnabled(): bool
    {
        return ($this->section()['enabled'] ?? true) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultSettings(int $userId): array
    {
        $device = $this->section()['device'] ?? null;
        $base   = is_array($device) && isset($device['deviceId'])
            ? (string) $device['deviceId']
            : 'manage-energy';

        return ['device_id' => $base . '-u' . $userId];
    }

    public function statusFor(int $userId, ?array $row): IntegrationStatus
    {
        if ($row === null || !$row['enabled']) {
            // Device qui sera utilisé une fois activé (dérivé, non secret).
            $device = $this->defaultSettings($userId)['device_id'];

            return new IntegrationStatus(
                IntegrationStatus::DISABLED,
                [IntegrationStatus::line('integration.energyid.off_hint', [], (string) $device)],
            );
        }

        $deviceId  = is_string($row['settings']['device_id'] ?? null)
            ? $row['settings']['device_id']
            : $this->defaultSettings($userId)['device_id'];
        $claimedAt = is_string($row['settings']['claimed_at'] ?? null)
            ? $row['settings']['claimed_at']
            : null;

        $claimLine = $claimedAt !== null
            ? IntegrationStatus::line('integration.energyid.claimed', ['date' => $claimedAt])
            : IntegrationStatus::line('integration.energyid.pending');

        return new IntegrationStatus(
            $claimedAt !== null ? IntegrationStatus::ACTIVE : IntegrationStatus::PENDING,
            [
                $claimLine,
                IntegrationStatus::line('integration.energyid.device', [], (string) $deviceId),
            ],
        );
    }

    public function syncUser(PDO $pdo, int $userId, array $settings, DateTimeImmutable $until, \Closure $logger): array
    {
        $deviceId = is_string($settings['device_id'] ?? null)
            ? $settings['device_id']
            : $this->defaultSettings($userId)['device_id'];

        // Device par utilisateur : template global + deviceId propre au membre.
        $device = array_merge($this->deviceTemplate(), ['deviceId' => $deviceId]);

        $service = new DailyLegacyWebhookSyncService(
            electricityRepository: new ElectricityReadingRepository($pdo, $userId),
            gasRepository:         new UtilityReadingRepository($pdo, $userId, 'gas'),
            waterRepository:       new UtilityReadingRepository($pdo, $userId, 'water'),
            syncState:             new WebhookSyncStateRepository($pdo, $userId),
            payloadFactory:        new EnergyIdPayloadFactory(),
            energyIdClient:        $this->client(),
            device:                $device,
            logger:                $logger,
        );

        $reports = $service->syncUntil($until);

        $claimedAt   = is_string($settings['claimed_at'] ?? null) ? $settings['claimed_at'] : null;
        // claimed_at = horodatage du run (temps PHP, comme les watermarks de sync).
        $interpreted = self::interpretReports($reports, $claimedAt, Dates::toDbString($until));

        if ($interpreted['claimLog'] !== null) {
            $logger($interpreted['claimLog']);
        }

        return ['ok' => $interpreted['ok'], 'settingsPatch' => $interpreted['settingsPatch']];
    }

    /**
     * Interprète les rapports de sync (logique pure, testable sans HTTP/BDD) :
     * un envoi réussi réclame le device côté EnergyID (claimed_at posé une seule
     * fois, à `$now`) ; un device non réclamé produit une ligne de log actionnable.
     *
     * @param list<array<string, mixed>> $reports
     * @param string                     $now horodatage à poser pour claimed_at (Y-m-d H:i:s)
     * @return array{ok: bool, settingsPatch: array<string, mixed>, claimLog: ?string}
     */
    public static function interpretReports(array $reports, ?string $claimedAt, string $now): array
    {
        $ok      = false;
        $patch   = [];
        $claimLog = null;

        foreach ($reports as $report) {
            $result = is_array($report['result'] ?? null) ? $report['result'] : [];

            if (($result['ok'] ?? false) === true) {
                $ok = true;
                if ($claimedAt === null) {
                    $patch['claimed_at'] = $now;
                }
                break;
            }

            if (($result['type'] ?? '') === 'not_claimed') {
                $claimLog = sprintf(
                    '[ACTION] Device non réclamé. claimCode=%s claimUrl=%s',
                    is_string($result['claimCode'] ?? null) ? $result['claimCode'] : '-',
                    is_string($result['claimUrl'] ?? null) ? $result['claimUrl'] : '-',
                );
            }
        }

        return ['ok' => $ok, 'settingsPatch' => $patch, 'claimLog' => $claimLog];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(): array
    {
        $section = $this->config['energyid'] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceTemplate(): array
    {
        $device = $this->section()['device'] ?? null;

        return is_array($device) ? $device : [];
    }

    /**
     * Client V2 partagé entre les utilisateurs d'un même run (construit une fois).
     */
    private function client(): EnergyIdV2Client
    {
        if ($this->client === null) {
            $section = $this->section();
            $this->client = new EnergyIdV2Client(
                http:               new HttpClient(),
                provisioningKey:    is_string($section['provisioning_key'] ?? null) ? $section['provisioning_key'] : '',
                provisioningSecret: is_string($section['provisioning_secret'] ?? null) ? $section['provisioning_secret'] : '',
                timeout:            (int) ($section['timeout'] ?? 15),
            );
        }

        return $this->client;
    }
}
