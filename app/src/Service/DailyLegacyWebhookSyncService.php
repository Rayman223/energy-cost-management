<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\WaterRepository;
use DateTimeImmutable;

final class DailyLegacyWebhookSyncService
{
    /**
     * State keys électricité pour webhook_sync_state.
     */
    private const ELEC_STATE_KEYS = [
        'prelevement-jour',
        'prelevement-nuit',
        'injection-jour',
        'injection-nuit',
        'production-solaire',
    ];

    private const GAS_STATE_KEY   = 'gas-index';
    private const WATER_STATE_KEY = 'water-index';

    public function __construct(
        private readonly LegacyDailyRepository $repository,
        private readonly GasRepository $gasRepository,
        private readonly WaterRepository $waterRepository,
        private readonly EnergyIdPayloadFactory $payloadFactory,
        private readonly EnergyIdV2Client $energyIdClient,
        private readonly array $device,
        private readonly \Closure|null $logger = null,
    ) {}

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

    private function syncElectricity(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->resolveElecFrom();
        $this->log(sprintf('[elec] Envoi à partir du: %s', $from->format('Y-m-d')));

        // Récupérer les 5 jeux de données
        $driesT1 = $this->repository->fetchDriesDailyFirstValues('Prelev_jour',  $from, $until);
        $driesT2 = $this->repository->fetchDriesDailyFirstValues('Prelev_nuit',  $from, $until);
        $driesI1 = $this->repository->fetchDriesDailyFirstValues('Injec_jour',   $from, $until);
        $driesI2 = $this->repository->fetchDriesDailyFirstValues('Injec_nuit',   $from, $until);
        $solar   = $this->repository->fetchSolaireDailyFirstValues($from, $until);

        // Fusionner par date
        $byDate = [];

        foreach ($driesT1 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']    = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el.t1'] = (float) $row['value'];
        }
        foreach ($driesT2 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']    = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el.t2'] = (float) $row['value'];
        }
        foreach ($driesI1 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']      = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el-i.t1'] = (float) $row['value'];
        }
        foreach ($driesI2 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']      = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el-i.t2'] = (float) $row['value'];
        }
        foreach ($solar as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts'] = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['pv'] = round((float) $row['value'] / 1000, 3);
        }

        if ($byDate === []) {
            $this->log('[elec] Aucune donnee a envoyer.');
            return null;
        }

        ksort($byDate);

        $metricKeys = ['el.t1', 'el.t2', 'el-i.t1', 'el-i.t2', 'pv'];
        $points     = [];

        foreach ($byDate as $data) {
            $point = ['ts' => $this->payloadFactory->unixTs($data['ts'])];
            foreach ($metricKeys as $key) {
                if (isset($data[$key])) {
                    $point[$key] = $data[$key];
                }
            }
            $points[] = $point;
        }

        $this->log(sprintf(
            '[elec] %d point(s) a envoyer (du %s au %s).',
            count($points),
            array_key_first($byDate),
            array_key_last($byDate)
        ));

        $result = $this->postWithRetry($session, $points, 'elec');

        if ($result['ok']) {
            $lastDate = array_key_last($byDate);
            $lastTs   = new DateTimeImmutable($byDate[$lastDate]['ts']);

            foreach (self::ELEC_STATE_KEYS as $stateKey) {
                $this->repository->saveLastSentAt($stateKey, $lastTs);
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

    private function syncGas(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->repository->getLastSentAt(self::GAS_STATE_KEY);
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
            $lastTs = new DateTimeImmutable($rows[array_key_last($rows)]['timestamp']);
            $this->repository->saveLastSentAt(self::GAS_STATE_KEY, $lastTs);

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

        return ['source' => 'Data_gaz', 'remoteId' => 'gas', 'result' => $result];
    }

    // ── Privé — Eau ───────────────────────────────────────────────────────────

    private function syncWater(array &$session, DateTimeImmutable $until): ?array
    {
        $from = $this->repository->getLastSentAt(self::WATER_STATE_KEY);
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
                'ts'    => $this->payloadFactory->unixTs($row['timestamp']),
                // EnergyID predefined key for domestic water : 'dw'
                'eau'   => (float) $row['value'],
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
            $lastTs = new DateTimeImmutable($rows[array_key_last($rows)]['timestamp']);
            $this->repository->saveLastSentAt(self::WATER_STATE_KEY, $lastTs);

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

        return ['source' => 'Data_eau', 'remoteId' => 'water', 'result' => $result];
    }

    // ── Privé — Helpers ───────────────────────────────────────────────────────

    /**
     * Retourne la plus ancienne date parmi les 5 clés d'état électricité.
     * Si une clé est absente, retombe sur 1970-01-01.
     */
    private function resolveElecFrom(): DateTimeImmutable
    {
        $earliest = null;

        foreach (self::ELEC_STATE_KEYS as $stateKey) {
            $lastSentAt = $this->repository->getLastSentAt($stateKey);

            if ($lastSentAt === null) {
                return new DateTimeImmutable('1970-01-01 00:00:00');
            }

            if ($earliest === null || $lastSentAt < $earliest) {
                $earliest = $lastSentAt;
            }
        }

        return $earliest ?? new DateTimeImmutable('1970-01-01 00:00:00');
    }

    /**
     * Envoie le payload avec 1 retry en cas de 401/404/429.
     *
     * @param array{webhookUrl:string,headers:array<string,string>,uploadInterval:int} $session
     * @param array<int,array<string,float|int>> $points
     */
    private function postWithRetry(array &$session, array $points, string $label): array
    {
        $this->log(sprintf('[post:%s] Envoi attempt 1...', $label));
        $first = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);

        if ($first['ok']) {
            $first['attempts'] = 1;
            $this->log(sprintf('[post:%s] Attempt 1 OK — status=%d', $label, $first['status']));
            return $first;
        }

        $this->log(sprintf(
            '[post:%s] Attempt 1 ECHEC — status=%d error=%s body=%s',
            $label,
            $first['status'] ?? 0,
            $first['error'] ?? '-',
            $first['body'] ?? '-'
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
            '[post:%s] Attempt 2 %s — status=%d',
            $label,
            $second['ok'] ? 'OK' : 'ECHEC',
            $second['status'] ?? 0
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