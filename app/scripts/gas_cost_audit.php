<?php

declare(strict_types=1);

/**
 * Audit d'écart entre le coût mensuel du dashboard et le coût de période
 * d'advances, pour un même compteur (issue #255).
 *
 * Usage :
 *   php gas_cost_audit.php --from=YYYY-MM-DD --to=YYYY-MM-DD [options]
 *
 * Options :
 *   --user=<id>        Utilisateur cible (défaut : 1er compte). Cf. UserContext.
 *   --energy=gas|water Compteur audité (défaut : gas).
 *   --json             Sortie JSON brute, à coller dans un ticket.
 *
 * LECTURE SEULE : aucune écriture, aucune transaction. Le service est câblé à
 * l'identique de app/routes/advances.php pour rendre LES chiffres de la page,
 * et `--to` est traité comme EXCLUSIF, exactement comme elle.
 *
 * Exemples :
 *   php gas_cost_audit.php --from=2026-01-01 --to=2026-07-01              # 1er semestre
 *   php gas_cost_audit.php --from=2026-01-01 --to=2026-07-01 --user=2 --json
 */

use App\Infrastructure\Database;
use App\Repository\DynamicPriceRepository;
use App\Repository\ElectricityReadingRepository;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Security\UserContext;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use App\Support\CliArguments;
use App\Support\Dates;
use App\Support\DynamicPricing;

require_once __DIR__ . '/../../vendor/autoload.php';

$cliArgs = $argv ?? [];

/** Récupère la valeur d'un argument --clé=valeur (null si absent). */
$arg = static fn (string $name): ?string => CliArguments::value($cliArgs, $name);

$energy = strtolower(trim((string) ($arg('energy') ?? 'gas')));
$asJson = in_array('--json', $cliArgs, true);

if (!in_array($energy, ['gas', 'water'], true)) {
    fwrite(STDERR, '[FATAL] --energy doit valoir gas ou water.' . PHP_EOL);
    exit(1);
}

/** Parse une date `Y-m-d` en minuit UTC, comme app/routes/advances.php. */
$parseDate = static function (?string $value, string $flag): DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        fwrite(STDERR, sprintf('[FATAL] --%s=YYYY-MM-DD requis.' . PHP_EOL, $flag));
        exit(1);
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, Dates::utc());
    if ($date === false || $date->format('Y-m-d') !== $value) {
        fwrite(STDERR, sprintf('[FATAL] --%s : date invalide « %s ».' . PHP_EOL, $flag, $value));
        exit(1);
    }

    return $date;
};

$from = $parseDate($arg('from'), 'from');
$to   = $parseDate($arg('to'), 'to');

if ($to <= $from) {
    fwrite(STDERR, '[FATAL] --to doit suivre --from (la borne de fin est exclusive).' . PHP_EOL);
    exit(1);
}

// ── Bootstrap DB + tenant ────────────────────────────────────────────────────
$config = require __DIR__ . '/../bootstrap.php';
try {
    $pdo     = (new Database($config['database']))->pdo();
    $userId  = UserContext::cliUserId($pdo, UserContext::parseCliUserArg());
    $users   = new UserRepository($pdo);
    $isAdmin = ($users->findById($userId)?->isAdmin()) ?? false;
    $profile = $users->getProfile($userId);
} catch (\Throwable $e) {
    fwrite(STDERR, '[FATAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$timezone = $profile->timezone ?? 'UTC';
$zone     = $profile?->biddingZone;
$zone     = ($zone !== null && $zone !== '')
    ? $zone
    : (string) ($config['dynamic_prices']['bidding_zone'] ?? DynamicPriceRepository::DEFAULT_ZONE);

$tariffRepo  = new TariffRepository($pdo, $userId, $isAdmin);
$utilityRepo = new UtilityReadingRepository($pdo, $userId, $energy);

$costSvc = new CostCalculationService(
    legacyRepo: new ElectricityReadingRepository($pdo, $userId, $timezone),
    tariffRepo: $tariffRepo,
    gasRepo: new UtilityReadingRepository($pdo, $userId, 'gas'),
    calculator: new TariffCalculatorService(),
    dynamicPriceRepo: new DynamicPriceRepository($pdo, $zone),
    dynamicEnabled: DynamicPricing::isEnabled($config),
    waterRepo: new UtilityReadingRepository($pdo, $userId, 'water'),
    supplierMarkupPerKwh: $profile->supplierMarkupPerKwh ?? 0.0,
    tariffTimezone: $timezone,
);

// ── 1. Grilles tarifaires actives ────────────────────────────────────────────
// Ordre PRÉSERVÉ : c'est $grids[0] que le repli de segmentsFor() retiendrait si
// le découpage jour par jour ne rendait aucun segment.
$grids = [];
foreach ($tariffRepo->findActiveGridsBetween($energy, $from, $to) as $grid) {
    $lines = [];
    foreach ($grid->lines as $key => $line) {
        $lines[$key] = ['kind' => $line->kind->value, 'amount' => $line->amount];
    }

    $grids[] = [
        'id'          => $grid->id,
        'name'        => $grid->name,
        'valid_from'  => $grid->validFrom->format('Y-m-d'),
        'valid_to'    => $grid->validTo?->format('Y-m-d'),
        'shared'      => $grid->isShared(),
        'pcs'         => $grid->pcsCoefficient,
        'vat_rate'    => $grid->vatRate,
        'starts_after_period' => $grid->validFrom >= $to,
        'lines'       => $lines,
    ];
}

// ── 2. Relevés utilisés, avec le débit implicite de chaque intervalle ─────────
// C'est cette colonne qui rend la saisonnalité visible : c'est elle qui invalide
// la répartition du volume au prorata des jours.
$rows      = $utilityRepo->getReadingsForRange(Dates::toDbString($from), Dates::toDbString($to));
$readings  = [];
$previous  = null;
foreach ($rows as $row) {
    $at    = (string) $row['reading_at'];
    $value = (float) $row['counter_m3'];
    $rate  = null;

    if ($previous !== null) {
        $days = (new DateTimeImmutable($at, Dates::utc()))->getTimestamp()
            - (new DateTimeImmutable($previous['at'], Dates::utc()))->getTimestamp();
        $days = $days / 86400.0;
        $rate = $days > 0.0 ? round(($value - $previous['value']) / $days, 3) : null;
    }

    $readings[] = ['reading_at' => $at, 'counter_m3' => $value, 'm3_per_day' => $rate];
    $previous   = ['at' => $at, 'value' => $value];
}

// ── 3. Voie mensuelle (dashboard) ────────────────────────────────────────────
/** @return array<string, mixed> */
$estimateMonth = static fn (int $y, int $m): array => $energy === 'gas'
    ? $costSvc->estimateMonthGas($y, $m)
    : $costSvc->estimateMonthWater($y, $m);

$months        = [];
$unavailable   = [];
$monthlyTotal  = 0.0;
$monthlyM3     = 0.0;
$monthlyKwh    = 0.0;
$monthlyLines  = [];

// Tous les mois calendaires intersectant [from, to[.
$cursor = $from->modify('first day of this month');
while ($cursor < $to) {
    $year  = (int) $cursor->format('Y');
    $month = (int) $cursor->format('n');
    $r     = $estimateMonth($year, $month);
    $label = $cursor->format('Y-m');

    if (($r['available'] ?? false) !== true || !isset($r['cost'])) {
        $unavailable[] = ['month' => $label, 'reason' => $r['reason'] ?? 'coût non calculé'];
        $cursor = $cursor->modify('+1 month');
        continue;
    }

    /** @var array<string, mixed> $cost */
    $cost           = $r['cost'];
    $total          = (float) ($cost['total'] ?? 0.0);
    $monthlyTotal  += $total;
    $monthlyM3     += (float) ($r['delta_m3'] ?? 0.0);
    $monthlyKwh    += (float) ($r['kwh'] ?? 0.0);

    foreach ((array) ($cost['lines'] ?? []) as $line) {
        $key = (string) ($line['key'] ?? '?');
        $monthlyLines[$key] ??= ['quantity' => 0.0, 'amount' => 0.0];
        $monthlyLines[$key]['quantity'] += (float) ($line['quantity'] ?? 0.0);
        $monthlyLines[$key]['amount']   += (float) ($line['amount'] ?? 0.0);
    }

    $months[] = [
        'month'    => $label,
        'delta_m3' => $r['delta_m3'] ?? null,
        'kwh'      => $r['kwh'] ?? null,
        'tariff'   => $r['tariff_name'] ?? null,
        'segments' => count((array) ($r['tariff_segments'] ?? [])),
        'total'    => round($total, 2),
    ];

    $cursor = $cursor->modify('+1 month');
}

// ── 4. Voie période libre (advances) ─────────────────────────────────────────
$period = $energy === 'gas'
    ? $costSvc->estimatePeriodGas($from, $to)
    : $costSvc->estimatePeriodWater($from, $to);

$periodLines = [];
$periodTotal = 0.0;
if (($period['available'] ?? false) === true && isset($period['cost'])) {
    /** @var array<string, mixed> $periodCost */
    $periodCost  = $period['cost'];
    $periodTotal = (float) ($periodCost['total'] ?? 0.0);
    foreach ((array) ($periodCost['lines'] ?? []) as $line) {
        $key = (string) ($line['key'] ?? '?');
        $periodLines[$key] ??= ['quantity' => 0.0, 'amount' => 0.0];
        $periodLines[$key]['quantity'] += (float) ($line['quantity'] ?? 0.0);
        $periodLines[$key]['amount']   += (float) ($line['amount'] ?? 0.0);
    }
}

// ── 5. Décomposition poste par poste ─────────────────────────────────────────
$byLine = [];
foreach (array_unique(array_merge(array_keys($monthlyLines), array_keys($periodLines))) as $key) {
    $m = $monthlyLines[$key] ?? ['quantity' => 0.0, 'amount' => 0.0];
    $p = $periodLines[$key]  ?? ['quantity' => 0.0, 'amount' => 0.0];

    $byLine[] = [
        'key'            => $key,
        'monthly_qty'    => round($m['quantity'], 3),
        'period_qty'     => round($p['quantity'], 3),
        'monthly_amount' => round($m['amount'], 2),
        'period_amount'  => round($p['amount'], 2),
        'delta'          => round($p['amount'] - $m['amount'], 2),
    ];
}

// ── 6. Causes probables ──────────────────────────────────────────────────────
$periodSegments = (array) ($period['tariff_segments'] ?? []);
$periodM3       = (float) ($period['delta_m3'] ?? 0.0);
$maxMonthly     = 0;
foreach ($months as $m) {
    $maxMonthly = max($maxMonthly, (int) $m['segments']);
}

$causes = [];
// Les deux voies concordent au centime : inutile d'égrener des divergences
// structurelles qui, sur CETTE fenêtre, ne coûtent rien.
$totalGap = abs($periodTotal - $monthlyTotal);

if ($unavailable !== [] && ($period['available'] ?? false) === true) {
    $causes[] = sprintf(
        'MOIS INDISPONIBLE(S) : %s. Ces mois affichent « — » sur le dashboard et '
        . 'manquent donc à la somme, alors que la période libre facture leurs jours.',
        implode(', ', array_column($unavailable, 'month')),
    );
} elseif ($unavailable !== []) {
    $causes[] = sprintf(
        'MOIS INDISPONIBLE(S) : %s — et la période libre l\'est aussi. Il manque des '
        . 'relevés ou une grille tarifaire sur cette fenêtre, rien à comparer.',
        implode(', ', array_column($unavailable, 'month')),
    );
}

if (count($periodSegments) === 1 && ($grids[0]['starts_after_period'] ?? false) === true) {
    $causes[] = sprintf(
        'REPLI SUR GRILLE FUTURE : toute la période est facturée avec « %s », dont '
        . 'valid_from (%s) est postérieur à la fin de période. Le découpage jour par '
        . 'jour n\'a rien rendu et segmentsFor() est retombé sur la grille la plus récente.',
        (string) $grids[0]['name'],
        (string) $grids[0]['valid_from'],
    );
}

if ($totalGap >= 0.01 && count($periodSegments) >= 2 && $maxMonthly <= 1) {
    $causes[] = sprintf(
        'DÉCOUPAGE TARIFAIRE : la période libre traverse %d grilles alors que chaque '
        . 'mois n\'en voit qu\'une. Vérifier que les volumes par sous-période suivent '
        . 'bien la consommation réelle (colonne m³/jour des relevés) et non les jours.',
        count($periodSegments),
    );
}

$m3Gap = $monthlyM3 - $periodM3;
if (abs($m3Gap) > 0.001) {
    // Fenêtre réellement couverte par les mois parcourus, pour proposer la borne
    // exclusive qui rend les deux voies comparables.
    $alignedTo = $from->modify('first day of this month')
        ->modify(sprintf('+%d month', count($months) + count($unavailable)))
        ->format('Y-m-d');

    $causes[] = sprintf(
        'VOLUMES DIFFÉRENTS : %+.3f m³ pour la période face à la somme mensuelle. '
        . 'La borne --to est EXCLUSIVE : pour couvrir exactement les mois parcourus, '
        . 'relancer avec --to=%s.',
        -$m3Gap,
        $alignedTo,
    );
}

foreach ($byLine as $line) {
    if ($line['key'] !== '?' && abs($line['monthly_qty'] - $line['period_qty']) > 0.001
        && abs($line['delta']) >= 0.01
    ) {
        $causes[] = sprintf(
            'POSTE « %s » : quantités divergentes (%.3f mensuel contre %.3f période) '
            . 'pour %+.2f €.',
            $line['key'],
            $line['monthly_qty'],
            $line['period_qty'],
            $line['delta'],
        );
    }
}

if ($causes === []) {
    $causes[] = $totalGap < 0.01
        ? 'Les deux voies concordent : aucune divergence à expliquer.'
        : 'Aucune divergence structurelle détectée : l\'écart résiduel tient aux arrondis.';
}

// ── Restitution ──────────────────────────────────────────────────────────────
$report = [
    'user_id'   => $userId,
    'energy'    => $energy,
    'timezone'  => $timezone,
    'from'      => $from->format('Y-m-d'),
    'to'        => $to->format('Y-m-d') . ' (exclu)',
    'grids'     => $grids,
    'readings'  => $readings,
    'monthly'   => [
        'months'      => $months,
        'unavailable' => $unavailable,
        'total'       => round($monthlyTotal, 2),
        'delta_m3'    => round($monthlyM3, 3),
        'kwh'         => round($monthlyKwh, 2),
    ],
    'period'    => [
        'available'         => $period['available'] ?? false,
        'reason'            => $period['reason'] ?? null,
        'days'              => $period['days'] ?? null,
        'coverage_complete' => $period['coverage_complete'] ?? null,
        'tariff'            => $period['tariff_name'] ?? null,
        'segments'          => array_map(
            static fn (array $s): array => [
                'name'  => $s['name'] ?? null,
                'from'  => $s['from'] ?? null,
                'to'    => $s['to'] ?? null,
                'days'  => $s['days'] ?? null,
                'total' => $s['total'] ?? null,
            ],
            $periodSegments,
        ),
        'total'    => round($periodTotal, 2),
        'delta_m3' => round($periodM3, 3),
        'kwh'      => $period['kwh'] ?? null,
    ],
    'by_line'   => $byLine,
    'gap'       => round($periodTotal - $monthlyTotal, 2),
    'causes'    => $causes,
];

if ($asJson) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

$out = static fn (string $line = ''): int|false => fwrite(STDOUT, $line . PHP_EOL);

$out(sprintf(
    '=== Audit %s — user #%d — %s → %s (exclu) — fuseau %s ===',
    $energy,
    $userId,
    $from->format('Y-m-d'),
    $to->format('Y-m-d'),
    $timezone,
));
$out();

$out('-- Grilles actives (ordre de résolution) --');
foreach ($grids as $g) {
    $out(sprintf(
        '  #%-4s %-24s %s → %-12s %s%s',
        (string) $g['id'],
        (string) $g['name'],
        (string) $g['valid_from'],
        $g['valid_to'] ?? 'illimité',
        $g['shared'] === true ? '[catalogue] ' : '',
        $g['starts_after_period'] === true ? '⚠ démarre APRÈS la période' : '',
    ));
}
if ($grids === []) {
    $out('  (aucune)');
}
$out();

$out('-- Relevés (m³/jour = débit implicite de l\'intervalle précédent) --');
foreach ($readings as $r) {
    $out(sprintf(
        '  %-19s %12.3f m³   %s',
        $r['reading_at'],
        $r['counter_m3'],
        $r['m3_per_day'] === null ? '' : sprintf('%8.3f m³/j', $r['m3_per_day']),
    ));
}
$out();

$out('-- Voie mensuelle (dashboard) --');
foreach ($months as $m) {
    $out(sprintf(
        '  %-8s %10.3f m³  %10s kWh  %-28s %d seg  %10.2f',
        $m['month'],
        (float) $m['delta_m3'],
        (string) $m['kwh'],
        (string) ($m['tariff'] ?? '—'),
        (int) $m['segments'],
        (float) $m['total'],
    ));
}
foreach ($unavailable as $u) {
    $out(sprintf('  %-8s INDISPONIBLE — %s', $u['month'], (string) $u['reason']));
}
$out(sprintf('  %-8s %10.3f m³                              TOTAL %10.2f', 'somme', $monthlyM3, $monthlyTotal));
$out();

$out('-- Voie période libre (advances) --');
if (($period['available'] ?? false) !== true) {
    $out('  INDISPONIBLE — ' . (string) ($period['reason'] ?? '?'));
} else {
    foreach ($periodSegments as $s) {
        $out(sprintf(
            '  %-24s %s → %-12s %4s j  %10.2f',
            (string) ($s['name'] ?? '?'),
            (string) ($s['from'] ?? '?'),
            (string) ($s['to'] ?? '?'),
            (string) ($s['days'] ?? '?'),
            (float) ($s['total'] ?? 0.0),
        ));
    }
    $out(sprintf('  %-24s %10.3f m³  %4s j          TOTAL %10.2f', 'période', $periodM3, (string) ($period['days'] ?? '?'), $periodTotal));
}
$out();

$out('-- Écart poste par poste (période − somme mensuelle) --');
$out(sprintf('  %-20s %12s %12s %12s %12s %10s', 'poste', 'qté mois', 'qté période', '€ mois', '€ période', 'écart'));
foreach ($byLine as $l) {
    $out(sprintf(
        '  %-20s %12.3f %12.3f %12.2f %12.2f %10.2f',
        $l['key'],
        $l['monthly_qty'],
        $l['period_qty'],
        $l['monthly_amount'],
        $l['period_amount'],
        $l['delta'],
    ));
}
$out(sprintf('  %-20s %12s %12s %12.2f %12.2f %10.2f', 'TOTAL', '', '', $monthlyTotal, $periodTotal, $report['gap']));
$out();

$out('-- Cause probable --');
foreach ($causes as $c) {
    $out('  • ' . $c);
}

exit(0);
