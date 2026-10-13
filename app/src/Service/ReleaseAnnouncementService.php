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
            if ($manifest['user_impact']) {
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
                $this->client->post($this->webhook, self::payload(array_reverse($pending)));
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
     * One embed per deployment, newest first. Full notes remain available in git.
     * @param non-empty-list<array{version: string, title: string, content: string}> $pending
     * @return array<string, mixed>
     */
    public static function payload(array $pending): array
    {
        $title = count($pending) === 1 ? $pending[0]['title']
            : 'Updates from version ' . $pending[count($pending) - 1]['version'] . ' to ' . $pending[0]['version'];
        $content = count($pending) === 1 ? $pending[0]['content'] : implode("\n\n", array_map(
            static fn (array $release): string => '**' . $release['title'] . "**\n" . $release['content'],
            $pending,
        ));
        $more = "\n\n[Full release notes](" . self::NOTES_URL . ')';

        return ['embeds' => [[
            'title' => $title,
            'url' => self::NOTES_URL,
            'description' => self::fit($content, 4096 - ReleaseNotesManifest::length($more)) . $more,
            'color' => 0x2563EB,
        ]], 'allowed_mentions' => ['parse' => []]];
    }

    private static function fit(string $text, int $max): string
    {
        if (ReleaseNotesManifest::length($text) <= $max) {
            return $text;
        }
        // Reserve space for an ellipsis and a closing code fence if the cut splits one.
        $cut = mb_substr($text, 0, $max - 5, 'UTF-8');
        while (ReleaseNotesManifest::length($cut) > $max - 5) {
            $cut = mb_substr($cut, 0, -1, 'UTF-8');
        }
        $newline = mb_strrpos($cut, "\n", 0, 'UTF-8');
        if ($newline !== false && $newline > intdiv($max, 2)) {
            $cut = mb_substr($cut, 0, $newline, 'UTF-8');
        }

        return rtrim($cut) . '…' . (substr_count($cut, '```') % 2 === 1 ? "\n```" : '');
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
