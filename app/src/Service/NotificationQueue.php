<?php

declare(strict_types=1);

namespace App\Service;

/** Host relay acknowledges only after notify succeeds. Never store commands. */
final class NotificationQueue
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        $result = [];
        foreach (['dynamic-prices', 'load-profiles'] as $job) {
            $path = $this->directory . '/' . $job . '.json';
            if (is_file($path)) {
                $item = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($item) && ($item['pending'] ?? false) === true) {
                    $result[] = $item;
                }
            }
        }
        return $result;
    }

    public function acknowledge(string $job, string $id): void
    {
        foreach ($this->pending() as $item) {
            if ($item['job'] === $job && $item['id'] === $id) {
                $item['pending'] = false;
                if (($item['kind'] ?? '') === 'missing') {
                    $item['notified'] = array_values(array_unique(array_merge($item['notified'] ?? [], $item['labels'] ?? [])));
                }
                SchedulerSchedule::writeJson($this->directory . '/' . $job . '.json', $item);
            }
        }
    }

    /** @param list<string> $missing */
    public function update(string $job, int $status, array $missing, string $url): void
    {
        $path = $this->directory . '/' . $job . '.json';
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $state = is_array($state) ? $state : [];
        $legacy = dirname($this->directory) . '/energy-load-profile.notified';
        if ($job === 'load-profiles' && !is_file($path) && is_file($legacy)) {
            $text = (string) file_get_contents($legacy);
            preg_match_all('/^([A-Za-z0-9_-]+) ([A-Z]{2}) (\d{4}-\d{2})$/m', $text, $rows, PREG_SET_ORDER);
            $state['notified'] = array_map(static fn (array $row): string => $row[1] . '/' . $row[2] . ' ' . $row[3], $rows);
            $state['error'] = str_contains($text, '__ERROR__');
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $error = $status !== 0 && !($job === 'load-profiles' && $status === 2);
        $notified = is_array($state['notified'] ?? null) ? $state['notified'] : [];
        if ($error) {
            // A failed check cannot prove that previously missing months recovered.
            $kind = 'error';
            $labels = [];
            $changed = ($state['error'] ?? false) !== true;
            $pending = $changed || ($state['pending'] ?? false) === true;
        } else {
            $notified = array_values(array_intersect($notified, $missing));
            $labels = array_values(array_diff($missing, $notified));
            $kind = $missing === [] ? 'ok' : 'missing';
            $pending = $labels !== [];
            $changed = $pending && (($state['kind'] ?? '') !== 'missing' || ($state['labels'] ?? []) !== $labels);
        }
        SchedulerSchedule::writeJson($path, [
            'job' => $job,
            'id' => $changed ? bin2hex(random_bytes(16)) : (string) ($state['id'] ?? ''),
            'kind' => $kind,
            'error' => $error,
            'missing' => $missing,
            'notified' => $notified,
            'labels' => $labels,
            'url' => $url,
            'pending' => $pending,
        ]);
    }
}
