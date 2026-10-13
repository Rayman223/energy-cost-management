<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\DiscordWebhook;
use App\Repository\Contract\ReleaseAnnouncementRepositoryInterface;
use RuntimeException;
use Throwable;

/** Publish delivered manifests only; never derive announcements from git at runtime. */
final class ReleaseAnnouncementService
{
    private const NOTES_URL = 'https://github.com/Rayman223/energy-cost-management/tree/main/releases';

    public function __construct(
        private readonly ReleaseAnnouncementRepositoryInterface $repository,
        private readonly DiscordWebhook $client,
        private readonly string $webhook,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $directory, string $installed, bool $publish = false): array
    {
        // Validate every delivered manifest before any write or network request.
        $releases = [];
        foreach (ReleaseNotesManifest::versionsUpTo($directory, $installed) as $version) {
            $manifest = ReleaseNotesManifest::load($directory, $version);
            if ($manifest['announcements'] !== []) {
                $releases[] = ['version' => $version] + $manifest['announcements']['en'];
            }
        }
        $result = ['ok' => true, 'version' => $installed];
        if (!ReleaseNotesManifest::exists($directory, $installed)) {
            $result['warning'] = ReleaseNotesManifest::MISSING_WARNING;
        }
        try {
            $execute = function () use ($releases, $publish): array {
                // Re-read receipts under the lock: a concurrent publisher may have finished.
                $sent = array_fill_keys($this->repository->sentVersions(), true);
                $pending = array_values(array_filter($releases, static fn (array $release): bool => !isset($sent[$release['version']])));
                $versions = array_column($pending, 'version');
                if ($this->webhook === '') {
                    return ['status' => 'disabled', 'versions' => $versions];
                }
                if (!DiscordWebhook::isValidUrl($this->webhook)) {
                    throw new RuntimeException('Invalid discord.release_webhook_en configuration.');
                }
                if ($pending === []) {
                    return ['status' => 'up_to_date', 'versions' => []];
                }
                if (!$publish) {
                    return ['status' => 'pending', 'versions' => $versions];
                }
                foreach (self::payloads(array_reverse($pending)) as $payload) {
                    $this->client->post($this->webhook, $payload);
                }
                $this->repository->markSent($versions);

                return ['status' => 'sent', 'versions' => $versions];
            };
            $result['languages'] = ['en' => $publish && $this->webhook !== ''
                ? $this->repository->withPublishLock($execute)
                : $execute()];
        } catch (Throwable $exception) {
            $result['ok'] = false;
            // Configuration values never appear in the result, including on transport failure.
            $result['languages'] = ['en' => ['status' => 'error', 'error' => $this->redact($exception->getMessage())]];
        }

        return $result;
    }

    /**
     * All deployment notes, newest first, split into messages without truncation.
     * @param non-empty-list<array{version: string, title: string, content: string}> $pending
     * @return non-empty-list<array<string, mixed>>
     */
    public static function payloads(array $pending): array
    {
        $title = count($pending) === 1 ? $pending[0]['title']
            : 'Updates from version ' . $pending[count($pending) - 1]['version'] . ' to ' . $pending[0]['version'];
        $content = count($pending) === 1 ? $pending[0]['content'] : implode("\n\n", array_map(
            static fn (array $release): string => '**' . $release['title'] . "**\n" . $release['content'],
            $pending,
        ));
        $more = "\n\n[Full release notes](" . self::NOTES_URL . ')';

        $chunks = self::split($content, 4096 - ReleaseNotesManifest::length($more));
        $payloads = [];
        foreach ($chunks as $index => $chunk) {
            $embed = [
                'title' => $title,
                'url' => self::NOTES_URL,
                'description' => $chunk . $more,
                'color' => 0x2563EB,
            ];
            if (count($chunks) > 1) {
                $embed['footer'] = ['text' => 'Part ' . ($index + 1) . '/' . count($chunks)];
            }
            $payloads[] = ['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
        }

        return $payloads;
    }

    /** @return non-empty-list<string> */
    private static function split(string $text, int $max): array
    {
        $chunks = [];
        $inCode = false;
        do {
            // Reserve closing/reopening fences; keep every original character.
            $limit = $max - 8;
            $cut = mb_substr($text, 0, $limit, 'UTF-8');
            while (ReleaseNotesManifest::length($cut) > $limit) {
                $cut = mb_substr($cut, 0, -1, 'UTF-8');
            }
            if (strlen($cut) < strlen($text)) {
                $newline = mb_strrpos($cut, "\n", 0, 'UTF-8');
                if ($newline !== false && $newline > intdiv($limit, 2)) {
                    $cut = mb_substr($cut, 0, $newline + 1, 'UTF-8');
                }
                // Do not split a triple-backtick delimiter at a character boundary.
                for ($i = 0; $i < 2 && str_ends_with($cut, '`') && ($text[strlen($cut)] ?? '') === '`'; ++$i) {
                    $cut = mb_substr($cut, 0, -1, 'UTF-8');
                }
            }
            $prefix = $inCode ? "```\n" : '';
            $inCode = $inCode !== (substr_count($cut, '```') % 2 === 1);
            $chunks[] = $prefix . $cut . ($inCode ? "\n```" : '');
            $text = substr($text, strlen($cut));
        } while ($text !== '');

        return $chunks;
    }

    private function redact(string $message): string
    {
        if ($this->webhook === '') {
            return $message;
        }
        $token = basename((string) parse_url($this->webhook, PHP_URL_PATH));
        $message = str_replace($this->webhook, '[redacted webhook]', $message);

        return $token !== '' ? str_replace($token, '[redacted token]', $message) : $message;
    }
}
