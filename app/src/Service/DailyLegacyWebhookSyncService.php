<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LegacyDailyRepository;
use DateTimeImmutable;

final class DailyLegacyWebhookSyncService
{
    /**
     * State keys pour webhook_sync_state (compatibilité dashboard).
     */
    private const STATE_KEYS = [
        'prelevement-jour',
        'prelevement-nuit',
        'injection-jour',
        'injection-nuit',
        'production-solaire',
    ];

    public function __construct(
        private readonly LegacyDailyRepository $repository,
        private readonly EnergyIdPayloadFactory $payloadFactory,
        private readonly EnergyIdV2Client $energyIdClient,
        private readonly array $device,
        private readonly \Closure|null $logger = null,
    ) {
    }

    public function syncUntil(DateTimeImmutable $until): array
    {
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

        $session = $hello;

        // ── 2. Déterminer la date de départ (la plus ancienne des 5 clés) ─
        $from = $this->resolveFrom();
        $this->log(sprintf('[sync] Envoi des données à partir du: %s', $from->format('Y-m-d')));

        // ── 3. Récupérer les 5 jeux de données ───────────────────────────
        $driesT1 = $this->repository->fetchDriesDailyFirstValues('Prelev_jour',  $from, $until);
        $driesT2 = $this->repository->fetchDriesDailyFirstValues('Prelev_nuit',  $from, $until);
        $driesI1 = $this->repository->fetchDriesDailyFirstValues('Injec_jour',   $from, $until);
        $driesI2 = $this->repository->fetchDriesDailyFirstValues('Injec_nuit',   $from, $until);
        $solar   = $this->repository->fetchSolaireDailyFirstValues($from, $until);

        // ── 4. Fusionner par date ─────────────────────────────────────────
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
            $this->log('[sync] Aucune donnee a envoyer.');
            return [];
        }

        // Trier par date croissante
        ksort($byDate);

        // ── 5. Construire le payload final ────────────────────────────────
        $metricKeys = ['el.t1', 'el.t2', 'el-i.t1', 'el-i.t2', 'pv'];
        $points = [];

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
            '[sync] %d point(s) a envoyer (du %s au %s).',
            count($points),
            array_key_first($byDate),
            array_key_last($byDate)
        ));

        // ── 6. Envoi unique avec retry ────────────────────────────────────
        $result = $this->postWithRetry($session, $points);

        if ($result['ok']) {
            $lastDate = array_key_last($byDate);
            $lastTs   = new DateTimeImmutable($byDate[$lastDate]['ts']);

            // Mettre à jour toutes les clés d'état (dashboard + cohérence)
            foreach (self::STATE_KEYS as $stateKey) {
                $this->repository->saveLastSentAt($stateKey, $lastTs);
            }

            $this->log(sprintf(
                '[sync] OK (attempt %d) — last_sent_at mis a jour: %s',
                $result['attempts'],
                $lastTs->format('Y-m-d H:i:s')
            ));
        } else {
            $this->log(sprintf(
                '[sync] ECHEC (attempt %d) — status=%s error=%s body=%s',
                $result['attempts'],
                $result['status'] ?? '?',
                $result['error'] ?? '-',
                $result['body'] ?? '-'
            ));
        }

        return [['source' => 'combined', 'remoteId' => 'all-metrics', 'result' => $result]];
    }

    // ── Privé ─────────────────────────────────────────────────────────────────

    /**
     * Retourne la plus ancienne date parmi les 5 clés d'état.
     * Si une clé n'a pas de valeur, on retombe sur 1970-01-01 (catch-all).
     */
    private function resolveFrom(): DateTimeImmutable
    {
        $earliest = null;

        foreach (self::STATE_KEYS as $stateKey) {
            $lastSentAt = $this->repository->getLastSentAt($stateKey);

            if ($lastSentAt === null) {
                // Une clé sans historique → on part de 1970 pour tout récupérer
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
    private function postWithRetry(array &$session, array $points): array
    {
        $this->log('[post] Envoi attempt 1...');
        $first = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);

        if ($first['ok']) {
            $first['attempts'] = 1;
            $this->log(sprintf('[post] Attempt 1 OK — status=%d', $first['status']));
            return $first;
        }

        $this->log(sprintf(
            '[post] Attempt 1 ECHEC — status=%d error=%s body=%s',
            $first['status'] ?? 0,
            $first['error'] ?? '-',
            $first['body'] ?? '-'
        ));

        // Token expiré → renouveler la session
        if (in_array((int) $first['status'], [401, 404], true)) {
            $this->log('[post] Token expire (401/404), re-appel /hello...');
            $hello = $this->energyIdClient->hello($this->device);
            if ($hello['ok']) {
                $session = $hello;
                $this->log('[post] /hello renouvele OK.');
            } else {
                $this->log('[post] /hello renouvellement ECHEC — type=' . ($hello['type'] ?? '?'));
            }
        }

        // Rate-limit → respecter le Retry-After
        if ((int) $first['status'] === 429) {
            $retryAfter = (int) ($first['headers']['retry-after'] ?? 1);
            $sleepSec   = max(1, min($retryAfter, 300));
            $this->log(sprintf('[post] Rate-limit 429, attente %ds (Retry-After=%s)...', $sleepSec, $retryAfter));
            sleep($sleepSec);
        }

        $this->log('[post] Envoi attempt 2...');
        $second             = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);
        $second['attempts'] = 2;

        $this->log(sprintf(
            '[post] Attempt 2 %s — status=%d',
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