<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\LoadProfileRepository;
use App\Service\LoadProfileFreshness;
use App\Support\ConfigUrl;

/**
 * Vérifie que les profils de charge utilisés par les tarifs indexés sont à jour (#101).
 *
 * Le RLP est un profil MESURÉ : Synergrid publie les coefficients d'un mois après sa
 * clôture, sans API, et il faut donc les importer chaque mois. Un oubli ne se voit
 * nulle part — le calcul retombe sans bruit sur le baseload. Ce script le détecte ;
 * il n'importe rien (l'import se fait sur /admin/load-profiles ou via
 * import_load_profile.php).
 *
 * Seuls les profils que des grilles `indexed_monthly` désignent sont vérifiés, et
 * seulement sur leur période de validité.
 *
 * SORTIE — une ligne par constat, préfixe entre crochets, lisible par
 * cron_load_profile_unraid.sh :
 *
 *   [OK]           RLP0N BE 2026-09 100.0
 *   [MISSING]      RLP0N BE 2026-09 0.0      (code, pays, mois, couverture %)
 *   [UPLOAD_URL]   https://exemple.tld/admin/load-profiles   (si seo.base_url est configurée)
 *   [DOWNLOAD_URL] https://www.synergrid.be/...
 *
 * CODE DE SORTIE : 0 = rien ne manque, 2 = au moins un mois manque, 1 = erreur.
 *
 * USAGE :
 *   php app/scripts/cron_load_profile_check.php [--grace-days=5] [--lookback=12] [--now=AAAA-MM-JJ]
 *
 *   --grace-days  Jours d'attente après la clôture d'un mois avant de l'exiger
 *                 (Synergrid publie après coup, sans calendrier fixe).
 *   --lookback    Nombre de mois exigibles examinés (les plus récents).
 *   --now         Date de référence, pour tester (défaut : aujourd'hui, UTC).
 *
 * Sur Unraid, passer par app/scripts/cron_load_profile_unraid.sh, qui notifie.
 */

$config = require __DIR__ . '/../bootstrap.php';

/** @var array<string, string> $args */
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $pair           = explode('=', substr($arg, 2), 2);
        $args[$pair[0]] = $pair[1] ?? '1';
    }
}

$graceDays = (int) ($args['grace-days'] ?? LoadProfileFreshness::DEFAULT_GRACE_DAYS);
$lookback  = (int) ($args['lookback'] ?? LoadProfileFreshness::DEFAULT_LOOKBACK_MONTHS);
if ($graceDays < 0 || $graceDays > 60 || $lookback < 1 || $lookback > 120) {
    fwrite(STDERR, "[ERROR] --grace-days doit valoir 0 à 60 et --lookback 1 à 120.\n");
    exit(1);
}

try {
    $now = new DateTimeImmutable((string) ($args['now'] ?? 'now'), new DateTimeZone('UTC'));
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] --now : date illisible.\n");
    exit(1);
}

try {
    $repo   = new LoadProfileRepository((new Database($config['database']))->pdo());
    $report = (new LoadProfileFreshness($graceDays, $lookback))->report(
        $repo->profilesInUse(),
        static fn (string $code, string $country, DateTimeImmutable $from, DateTimeImmutable $to): array
            => $repo->pointsByMonth($code, $country, $from, $to),
        $now,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}

if ($report === []) {
    echo "[SKIP] Aucune grille indexée ne désigne de profil : rien à vérifier.\n";
    exit(0);
}

foreach ($report as $profile) {
    foreach ($profile['months'] as $month => $pct) {
        printf(
            "[%s] %s %s %s %.1f\n",
            LoadProfileFreshness::isComplete($pct) ? 'OK' : 'MISSING',
            $profile['code'],
            $profile['country'],
            $month,
            $pct,
        );
    }
}

$missing = LoadProfileFreshness::missing($report);
if ($missing === []) {
    exit(0);
}

// Lien vers la page d'import : seulement depuis l'URL canonique configurée. En CLI,
// il n'y a ni Host ni chemin de requête dont la déduire — une URL devinée enverrait
// l'admin vers « http://localhost ». Le script Unraid sait la fournir autrement.
$baseUrl = ConfigUrl::httpUrl($config, 'seo', 'base_url');
if ($baseUrl !== null) {
    echo '[UPLOAD_URL] ' . rtrim($baseUrl, '/') . '/' . LoadProfileFreshness::UPLOAD_PATH . "\n";
}
echo '[DOWNLOAD_URL] ' . LoadProfileFreshness::DOWNLOAD_URL . "\n";

exit(2);
