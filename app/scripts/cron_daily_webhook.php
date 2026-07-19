<?php

declare(strict_types=1);

/**
 * ALIAS DÉPRÉCIÉ (#70) — remplacé par cron_export_sync.php (système de modules
 * d'export). Conservé pour ne pas casser les crontabs existantes ; à retirer
 * après migration des planifications. Voir README (section « Tâches cron »).
 */

fwrite(STDERR, "[deprecated] cron_daily_webhook.php est un alias — utilisez app/scripts/cron_export_sync.php\n");

require __DIR__ . '/cron_export_sync.php';
