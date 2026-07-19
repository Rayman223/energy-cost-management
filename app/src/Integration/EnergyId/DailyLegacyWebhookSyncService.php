<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

use App\Domain\SyncStateKeys;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UtilityReadingRepository;
use App\Repository\WebhookSyncStateRepository;
use DateTimeImmutable;
use DateTimeZone;

final class DailyLegacyWebhookSyncService
{
    /** @param array<string, mixed> $device */
    public function __construct(
        private readonly ElectricityReadingRepository $electricityRepository,
        private readonly UtilityReadingRepository $gasRepository,
        private readonly UtilityReadingRepository $waterRepository,
        private readonly WebhookSyncStateRepository $syncState,
        private readonly EnergyIdPayloadFactory $payloadFactory,
        private readonly EnergyIdV2Client $energyIdClient,
        private readonly array $device,
        private readonly \Closure|null $logger = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function syncUntil(DateTimeImmutable $until): array
    {
        $reports = [];

        // ── 1. Provisioning ───────────────────────────────────────────────
        $this->log('[hello] Appel /hello vers EnergyID...');
        $hello = $this->energyIdClient->hello($this->device);

        if (!$hello['ok']) {
            $this->log('[hello] ECHEC — type=' . ($hello['type'] ?? '?'));
            return [['source' => 'provisioning', 'remoteId' => 'hello', 'result' => $hello]];
        }

        $this->log(sprintf(
            '[hello] OK — webhookUrl=%s uploadInterval=%ds',
            $hello['webhookUrl'],
            $hello['uploadInterval']
        ));

        // La session est partagée entre tous les blocs d'envoi.
        // postWithRetry() la renouvelle par référence si 401/404.
        $session = $hello;

        // ── 2. Sync électricité + solaire ─────────────────────────────────
        $elecReport = $this->syncElectricity($session, $until);
        if ($elecReport !== null) {
            $reports[] = $elecReport;
        }

        // ── 3. Sync gaz ───────────────────────────────────────────────────
        $gasReport = $this->syncGas($session, $until);
        if ($gasReport !== null) {
            $reports[] = $gasReport;
        }

        // ── 4. Sync eau ───────────────────────────────────────────────────
        $waterReport = $this->syncWater($session, $until);
        if ($waterReport !== null) {
            $reports[] = $waterReport;
        }

        return $reports;
    }

    // ── Privé — Électricité ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function syncElectricity(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->resolveElecFrom();
        $this->log(sprintf('[elec] Envoi à partir du: %s', $from->format('Y-m-d')));

        // Récupérer les 5 jeux de données (un registre par flux)
        $driesT1 = $this->electricityRepository->fetchDailyFirstValues('import_t1',  $from, $until);
        $driesT2 = $this->electricityRepository->fetchDailyFirstValues('import_t2',  $from, $until);
        $driesI1 = $this->electricityRepository->fetchDailyFirstValues('export_t1',  $from, $until);
        $driesI2 = $this->electricityRepository->fetchDailyFirstValues('export_t2',  $from, $until);
        $solar   = $this->electricityRepository->fetchDailyFirstValues('production', $from, $until);

        // Fusion par date + assemblage des points (logique pure, testée dans
        // ElectricityReadingMergerTest).
        $elec = (new ElectricityReadingMerger($this->payloadFactory))
            ->build($driesT1, $driesT2, $driesI1, $driesI2, $solar);

        if ($elec->isEmpty()) {
            $this->log('[elec] Aucune donnee a envoyer.');
            return null;
        }

        $this->log(sprintf(
            '[elec] %d point(s) a envoyer (du %s au %s).',
            count($elec->points),
            $elec->firstDate,
            $elec->lastDate
        ));

        $result = $this->postWithRetry($session, $elec->points, 'elec');

        if ($result['ok']) {
            $lastTs = new DateTimeImmutable($elec->lastTimestamp, new DateTimeZone('UTC'));

            foreach (SyncStateKeys::ELEC as $stateKey) {
                $this->syncState->saveLastSentAt($stateKey, $lastTs);
            }

            $this->log(sprintf(
                '[elec] OK (attempt %d) — last_sent_at mis a jour: %s',
                $result['attempts'],
                $lastTs->format('Y-m-d H:i:s')
            ));
        } else {
            $this->log(sprintf(
                '[elec] ECHEC (attempt %d) — status=%s error=%s body=%s',
                $result['attempts'],
                $result['status'] ?? '?',
                $result['error'] ?? '-',
                $result['body'] ?? '-'
            ));
        }

        return ['source' => 'combined-elec', 'remoteId' => 'elec+pv', 'result' => $result];
    }

    // ── Privé — Gaz ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function syncGas(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->syncState->getLastSentAt(SyncStateKeys::GAS);
        $this->log(sprintf(
            '[gas] Envoi à partir du: %s',
            $from !== null ? $from->format('Y-m-d H:i:s') : '(aucun historique)'
        ));

        $rows = $this->gasRepository->fetchReadingsSince($from, $until);

        if ($rows === []) {
            $this->log('[gas] Aucune donnee a envoyer.');
            return null;
        }

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'ts'  => $this->payloadFactory->unixTs($row['timestamp']),
                'gas' => (float) $row['value'],
            ];
        }

        $this->log(sprintf(
            '[gas] %d relevé(s) a envoyer (du %s au %s).',
            count($points),
            $rows[0]['timestamp'],
            $rows[array_key_last($rows)]['timestamp']
        ));

        $result = $this->postWithRetry($session, $points, 'gas');

        if ($result['ok']) {
            $lastTs = new DateTimeImmutable($rows[array_key_last($rows)]['timestamp'], new DateTimeZone('UTC'));
            $this->syncState->saveLastSentAt(SyncStateKeys::GAS, $lastTs);

            $this->log(sprintf(
                '[gas] OK (attempt %d) — last_sent_at mis a jour: %s',
                $result['attempts'],
                $lastTs->format('Y-m-d H:i:s')
            ));
        } else {
            $this->log(sprintf(
                '[gas] ECHEC (attempt %d) — status=%s error=%s body=%s',
                $result['attempts'],
                $result['status'] ?? '?',
                $result['error'] ?? '-',
                $result['body'] ?? '-'
            ));
        }

        return ['source' => 'gas-readings', 'remoteId' => 'gas', 'result' => $result];
    }

    // ── Privé — Eau ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function syncWater(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->syncState->getLastSentAt(SyncStateKeys::WATER);
        $this->log(sprintf(
            '[water] Envoi à partir du: %s',
            $from !== null ? $from->format('Y-m-d H:i:s') : '(aucun historique)'
        ));

        $rows = $this->waterRepository->fetchReadingsSince($from, $until);

        if ($rows === []) {
            $this->log('[water] Aucune donnee a envoyer.');
            return null;
        }

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'ts' => $this->payloadFactory->unixTs($row['timestamp']),
                // EnergyID predefined key for domestic water : 'dw'
                'dw' => round((float) $row['value'] * 1000, 0),  // m³ → litres
            ];
        }

        $this->log(sprintf(
            '[water] %d relevé(s) a envoyer (du %s au %s).',
            count($points),
            $rows[0]['timestamp'],
            $rows[array_key_last($rows)]['timestamp']
        ));

        $result = $this->postWithRetry($session, $points, 'water');

        if ($result['ok']) {
            $lastTs = new DateTimeImmutable($rows[array_key_last($rows)]['timestamp'], new DateTimeZone('UTC'));
            $this->syncState->saveLastSentAt(SyncStateKeys::WATER, $lastTs);

            $this->log(sprintf(
                '[water] OK (attempt %d) — last_sent_at mis a jour: %s',
                $result['attempts'],
                $lastTs->format('Y-m-d H:i:s')
            ));
        } else {
            $this->log(sprintf(
                '[water] ECHEC (attempt %d) — status=%s error=%s body=%s',
                $result['attempts'],
                $result['status'] ?? '?',
                $result['error'] ?? '-',
                $result['body'] ?? '-'
            ));
        }

        return ['source' => 'water-readings', 'remoteId' => 'water', 'result' => $result];
    }

    // ── Privé — Helpers ───────────────────────────────────────────────────────

    /**
     * Retourne la plus ancienne date parmi les 5 clés d'état électricité.
     * Si une clé est absente, retombe sur 1970-01-01.
     */
    private function resolveElecFrom(): DateTimeImmutable
    {
        $earliest = null;

        foreach (SyncStateKeys::ELEC as $stateKey) {
            $lastSentAt = $this->syncState->getLastSentAt($stateKey);

            if ($lastSentAt === null) {
                return new DateTimeImmutable('1970-01-01 00:00:00', new DateTimeZone('UTC'));
            }

            if ($earliest === null || $lastSentAt < $earliest) {
                $earliest = $lastSentAt;
            }
        }

        return $earliest ?? new DateTimeImmutable('1970-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Envoie le payload avec 1 retry en cas de 401/404/429.
     * Le body de réponse EnergyID est loggué systématiquement (succès ou échec)
     * pour faciliter le diagnostic.
     *
     * @param array{webhookUrl:string,headers:array<string,string>,uploadInterval:int} $session
     * @param array<int,array<string,float|int>> $points
     * @return array<string, mixed>
     */
    private function postWithRetry(array &$session, array $points, string $label): array
    {
        $this->log(sprintf('[post:%s] Envoi attempt 1...', $label));
        $first = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);

        if ($first['ok']) {
            $first['attempts'] = 1;
            $this->log(sprintf(
                '[post:%s] Attempt 1 OK — status=%d body=%s',
                $label,
                $first['status'],
                $first['body'] !== '' ? $first['body'] : '(vide)'
            ));
            return $first;
        }

        $this->log(sprintf(
            '[post:%s] Attempt 1 ECHEC — status=%d error=%s body=%s',
            $label,
            $first['status'] ?? 0,
            $first['error'] ?? '-',
            $first['body'] !== '' ? $first['body'] : '(vide)'
        ));

        // Token expiré → renouveler la session
        if (in_array((int) $first['status'], [401, 404], true)) {
            $this->log(sprintf('[post:%s] Token expire (401/404), re-appel /hello...', $label));
            $hello = $this->energyIdClient->hello($this->device);
            if ($hello['ok']) {
                $session = $hello;
                $this->log(sprintf('[post:%s] /hello renouvele OK.', $label));
            } else {
                $this->log(sprintf('[post:%s] /hello renouvellement ECHEC — type=%s', $label, $hello['type'] ?? '?'));
            }
        }

        // Rate-limit → respecter le Retry-After
        if ((int) $first['status'] === 429) {
            $retryAfter = (int) ($first['headers']['retry-after'] ?? 1);
            $sleepSec   = max(1, min($retryAfter, 300));
            $this->log(sprintf('[post:%s] Rate-limit 429, attente %ds...', $label, $sleepSec));
            sleep($sleepSec);
        }

        $this->log(sprintf('[post:%s] Envoi attempt 2...', $label));
        $second             = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);
        $second['attempts'] = 2;

        $this->log(sprintf(
            '[post:%s] Attempt 2 %s — status=%d body=%s',
            $label,
            $second['ok'] ? 'OK' : 'ECHEC',
            $second['status'] ?? 0,
            ($second['body'] ?? '') !== '' ? $second['body'] : '(vide)'
        ));

        return $second;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}