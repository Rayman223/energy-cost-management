<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Infrastructure\DiscordWebhook;
use App\Repository\ReleaseAnnouncementRepository;
use App\Service\ReleaseAnnouncementService;
use App\Service\ReleaseNotesManifest;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$mode = null;
$override = null;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--validate', '--status', '--publish'], true) && $mode === null) {
        $mode = $argument;
    } elseif (str_starts_with($argument, '--version=') && $override === null) {
        $override = substr($argument, strlen('--version='));
    } else {
        $mode = null;
        break;
    }
}
if ($mode === null || ($override !== null && $mode !== '--validate')) {
    fwrite(STDERR, "Usage: php app/scripts/release-notes.php --validate [--version=X.Y.Z] | --status | --publish\n");
    exit(1);
}

try {
    $version = $override ?? ReleaseNotesManifest::installedVersion($root);
    $directory = $root . '/releases';
    if ($mode === '--validate') {
        // No config.php, DB connection or webhook is needed to prepare a release.
        $present = ReleaseNotesManifest::exists($directory, $version);
        $hasNotes = $present && ReleaseNotesManifest::load($directory, $version)['announcements'] !== [];
        $result = ['ok' => true, 'version' => $version, 'status' => $hasNotes ? 'validated' : 'ignored'];
        if (!$present) {
            $result['warning'] = ReleaseNotesManifest::MISSING_WARNING;
        }
    } else {
        $config = require dirname(__DIR__) . '/bootstrap.php';
        $webhook = $config['discord']['release_webhook_en'] ?? '';
        if (!is_string($webhook)) {
            throw new RuntimeException('discord.release_webhook_en must be a string.');
        }
        $database = new Database($config['database']);
        $service = new ReleaseAnnouncementService(new ReleaseAnnouncementRepository($database->pdo()), new DiscordWebhook(), trim($webhook));
        $result = $service->run($directory, $version, $mode === '--publish');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
