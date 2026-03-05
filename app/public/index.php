<?php
declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\GasRepository;
use App\Repository\LegacyDailyRepository;
use App\Repository\TariffRepository;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;

// Bootstrap — graceful degradation if DB unavailable
$dbError = null;
$today   = null;
$deltas  = null;
$cost    = null;
$syncStatus = null;
$gasLatest  = null;

try {
    $config     = require __DIR__ . '/../bootstrap.php';

    $db         = new Database($config['database']);
    $pdo        = $db->pdo();
    $legacyRepo = new LegacyDailyRepository($pdo);
    $gasRepo    = new GasRepository($pdo);
    $tariffRepo = new TariffRepository($pdo);
    $costSvc    = new CostCalculationService($legacyRepo, $tariffRepo, $gasRepo, new TariffCalculatorService());

    $deltas     = $legacyRepo->getMonthlyDeltas();
    $cost       = $costSvc->estimateCurrentMonthElectricity();
    $gasLatest  = $gasRepo->getLatest();
    $syncStatus = [
        'prelevement_jour'   => $legacyRepo->getLastSentAt('prelevement-jour')?->format('d/m H:i'),
        'prelevement_nuit'   => $legacyRepo->getLastSentAt('prelevement-nuit')?->format('d/m H:i'),
        'injection_jour'     => $legacyRepo->getLastSentAt('injection-jour')?->format('d/m H:i'),
        'injection_nuit'     => $legacyRepo->getLastSentAt('injection-nuit')?->format('d/m H:i'),
        'production_solaire' => $legacyRepo->getLastSentAt('production-solaire')?->format('d/m H:i'),
    ];
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

function fmt(mixed $v, int $dec = 3, string $unit = 'kWh'): string
{
    if ($v === null || $v === false) return '<span class="nd">—</span>';
    return '<span class="val">' . number_format((float)$v, $dec, '.', ' ') . '</span> <span class="unit">' . $unit . '</span>';
}

function fmtCost(mixed $v): string
{
    if ($v === null) return '<span class="nd">—</span>';
    $sign = (float)$v < 0 ? '−' : '';
    return $sign . number_format(abs((float)$v), 2, '.', ' ') . ' €';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Energy — Brussels</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  /* ── Reset & tokens ────────────────────────────────────────────────────── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0b0d10;
    --surface:   #111318;
    --surface2:  #181c23;
    --border:    #1f2530;
    --border2:   #2a3040;
    --text:      #c8d0dc;
    --muted:     #556070;
    --amber:     #f5a623;
    --amber-dim: rgba(245,166,35,.12);
    --green:     #2fd58e;
    --green-dim: rgba(47,213,142,.10);
    --blue:      #41b3f5;
    --blue-dim:  rgba(65,179,245,.10);
    --red:       #f56565;
    --red-dim:   rgba(245,101,101,.10);
    --mono: 'Space Mono', monospace;
    --sans: 'Syne', sans-serif;
  }

  html { font-size: 15px; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    min-height: 100vh;
    /* Subtle dot-grid background */
    background-image: radial-gradient(circle, #1e2530 1px, transparent 1px);
    background-size: 28px 28px;
  }

  /* ── Layout ────────────────────────────────────────────────────────────── */
  .wrap { max-width: 1280px; margin: 0 auto; padding: 0 24px 60px; }

  /* ── Header ────────────────────────────────────────────────────────────── */
  header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 22px 0 18px;
    border-bottom: 1px solid var(--border);
    gap: 16px; flex-wrap: wrap;
  }
  .logo { display: flex; align-items: center; gap: 14px; }
  .logo-icon {
    width: 40px; height: 40px;
    background: var(--amber);
    border-radius: 8px;
    display: grid; place-items: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 0 20px rgba(245,166,35,.35);
  }
  .logo-text { font-size: 1.15rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #eef2f8; }
  .logo-sub  { font-family: var(--mono); font-size: .68rem; color: var(--muted); letter-spacing: .08em; margin-top: 1px; }

  .header-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
  .clock { font-family: var(--mono); font-size: .8rem; color: var(--muted); text-align: right; }
  .clock span { display: block; }
  .clock .time { font-size: 1.05rem; color: var(--text); }

  .sync-badge {
    display: flex; align-items: center; gap: 8px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 6px; padding: 6px 12px;
    font-family: var(--mono); font-size: .72rem; color: var(--muted);
  }
  .sync-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 8px var(--green);
    animation: pulse 2.4s ease-in-out infinite;
  }
  .sync-dot.stale { background: var(--amber); box-shadow: 0 0 8px var(--amber); }
  .sync-dot.error { background: var(--red);   box-shadow: 0 0 8px var(--red); animation: none; }

  @keyframes pulse {
    0%,100% { opacity: 1; }
    50%      { opacity: .35; }
  }

  /* ── Section titles ─────────────────────────────────────────────────────── */
  .section-header {
    display: flex; align-items: baseline; gap: 12px;
    margin: 36px 0 16px;
  }
  .section-title {
    font-size: .65rem; font-weight: 700; letter-spacing: .15em;
    text-transform: uppercase; color: var(--muted);
  }
  .section-line { flex: 1; height: 1px; background: var(--border); }

  /* ── Cards ──────────────────────────────────────────────────────────────── */
  .cards { display: grid; gap: 12px; }
  .cards-5 { grid-template-columns: repeat(5, 1fr); }
  .cards-4 { grid-template-columns: repeat(4, 1fr); }
  .cards-3 { grid-template-columns: repeat(3, 1fr); }
  .cards-2 { grid-template-columns: repeat(2, 1fr); }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px 20px;
    transition: border-color .2s;
  }
  .card:hover { border-color: var(--border2); }
  .card-label {
    font-size: .62rem; font-weight: 700; letter-spacing: .12em;
    text-transform: uppercase; color: var(--muted);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
  }
  .card-label .dot {
    width: 6px; height: 6px; border-radius: 50%;
    display: inline-block; flex-shrink: 0;
  }
  .card-value {
    font-family: var(--mono);
    font-size: 1.6rem;
    font-weight: 700;
    letter-spacing: -.02em;
    line-height: 1;
    color: #eef2f8;
  }
  /*.card-value .val { }*/
  .card-value .unit { font-size: .85rem; font-weight: 400; color: var(--muted); margin-left: 4px; }
  .card-value .nd   { color: var(--muted); font-size: 1.1rem; }
  .card-sub {
    font-family: var(--mono); font-size: .68rem; color: var(--muted);
    margin-top: 8px;
  }

  .card.amber { border-color: rgba(245,166,35,.2); background: linear-gradient(135deg, var(--surface) 60%, var(--amber-dim)); }
  .card.green { border-color: rgba(47,213,142,.2); background: linear-gradient(135deg, var(--surface) 60%, var(--green-dim)); }
  .card.blue  { border-color: rgba(65,179,245,.2);  background: linear-gradient(135deg, var(--surface) 60%, var(--blue-dim)); }
  .card.red   { border-color: rgba(245,101,101,.2); background: linear-gradient(135deg, var(--surface) 60%, var(--red-dim)); }

  /* ── Cost breakdown ────────────────────────────────────────────────────── */
  .cost-wrap { display: grid; grid-template-columns: 1fr 320px; gap: 12px; }

  .cost-lines { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px 24px; }
  .cost-line {
    display: flex; justify-content: space-between; align-items: center;
    padding: 9px 0; border-bottom: 1px solid var(--border); font-size: .88rem;
  }
  .cost-line:last-child { border: none; }
  .cost-line .cl-label { color: var(--muted); font-size: .8rem; }
  .cost-line .cl-amount { font-family: var(--mono); font-size: .9rem; color: var(--text); }
  .cost-line.total .cl-label { color: var(--text); font-weight: 700; font-size: .88rem; }
  .cost-line.total .cl-amount { color: var(--amber); font-size: 1.1rem; font-weight: 700; }
  .cost-line.credit .cl-amount { color: var(--green); }
  .cost-line.vat .cl-label { color: var(--muted); font-style: italic; }

  .cost-total-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 24px;
    display: flex; flex-direction: column; justify-content: space-between;
  }
  .cost-total-label {
    font-size: .62rem; font-weight: 700; letter-spacing: .15em;
    text-transform: uppercase; color: var(--muted); margin-bottom: 16px;
  }
  .cost-total-amount {
    font-family: var(--mono); font-size: 2.8rem; font-weight: 700;
    color: var(--amber); letter-spacing: -.04em; line-height: 1;
  }
  .cost-total-meta { font-family: var(--mono); font-size: .72rem; color: var(--muted); margin-top: 12px; }
  .cost-total-meta span { display: block; margin-top: 3px; }
  .no-tariff {
    background: var(--surface); border: 1px dashed var(--border2);
    border-radius: 10px; padding: 24px; color: var(--muted);
    font-size: .85rem; line-height: 1.6;
    grid-column: 1 / -1;
  }
  .no-tariff a { color: var(--amber); text-decoration: none; }
  .no-tariff a:hover { text-decoration: underline; }

  /* ── Chart ──────────────────────────────────────────────────────────────── */
  .chart-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; padding: 20px 24px;
  }
  .chart-card canvas { display: block; width: 100% !important; height: 260px !important; }

  /* ── Error banner ───────────────────────────────────────────────────────── */
  .error-banner {
    background: var(--red-dim); border: 1px solid rgba(245,101,101,.3);
    border-radius: 8px; padding: 14px 18px; margin-top: 24px;
    font-family: var(--mono); font-size: .8rem; color: var(--red);
    line-height: 1.6;
  }
  .error-banner strong { display: block; margin-bottom: 4px; }

  /* ── Gas ────────────────────────────────────────────────────────────────── */
  .gas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  .gas-history { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  thead th {
    background: var(--surface2); color: var(--muted);
    font-size: .62rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    padding: 10px 16px; text-align: left;
  }
  tbody tr { border-top: 1px solid var(--border); }
  tbody tr:hover { background: var(--surface2); }
  tbody td { padding: 10px 16px; font-family: var(--mono); color: var(--text); font-size: .8rem; }
  .td-delta { color: var(--blue); }
  .td-empty { padding: 24px 16px; color: var(--muted); font-style: italic; font-size: .82rem; }

  .gas-form { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 22px 24px; }
  .form-row { margin-bottom: 16px; }
  .form-label { display: block; font-size: .65rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); margin-bottom: 8px; }
  .form-input {
    width: 100%; background: var(--bg);
    border: 1px solid var(--border2); border-radius: 6px;
    padding: 9px 14px; color: var(--text); font-family: var(--mono); font-size: .88rem;
    outline: none; transition: border-color .2s;
  }
  .form-input:focus { border-color: var(--amber); }
  .btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 20px; border-radius: 6px;
    font-family: var(--sans); font-size: .82rem; font-weight: 700;
    letter-spacing: .04em; text-transform: uppercase;
    cursor: pointer; border: none; transition: opacity .15s, transform .1s;
  }
  .btn:active { transform: scale(.97); }
  .btn-amber { background: var(--amber); color: #0b0d10; }
  .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border2); }
  .btn-ghost:hover { color: var(--text); border-color: var(--border2); }
  .btn:disabled { opacity: .4; cursor: not-allowed; }
  .form-feedback { margin-top: 10px; font-family: var(--mono); font-size: .78rem; min-height: 20px; }
  .form-feedback.ok  { color: var(--green); }
  .form-feedback.err { color: var(--red); }

  /* ── Sync status ────────────────────────────────────────────────────────── */
  .sync-table { width: 100%; }
  .sync-table td:first-child { color: var(--muted); font-size: .78rem; padding-right: 16px; }
  .sync-table td:last-child  { font-family: var(--mono); font-size: .78rem; color: var(--text); }
  .sync-table tr { border-top: 1px solid var(--border); }
  .sync-table tr:first-child { border: none; }
  .sync-table td { padding: 8px 16px; }

  /* ── Tools strip ────────────────────────────────────────────────────────── */
  .tools-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
  .tool-link {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 6px;
    border: 1px solid var(--border2); color: var(--muted);
    font-size: .78rem; font-weight: 600; text-decoration: none;
    transition: color .15s, border-color .15s;
  }
  .tool-link:hover { color: var(--text); border-color: var(--blue); }

  /* ── Responsive ─────────────────────────────────────────────────────────── */
  @media (max-width: 1024px) {
    .cards-5 { grid-template-columns: repeat(3, 1fr); }
    .cost-wrap { grid-template-columns: 1fr; }
  }
  @media (max-width: 768px) {
    .cards-5, .cards-4 { grid-template-columns: repeat(2, 1fr); }
    .cards-3 { grid-template-columns: 1fr 1fr; }
    .gas-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 480px) {
    .cards-5, .cards-4, .cards-3, .cards-2 { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>
<div class="wrap">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <header>
    <div class="logo">
      <div class="logo-icon">⚡</div>
      <div>
        <div class="logo-text">Manage Energy</div>
        <div class="logo-sub">Brussels · Dries installation</div>
      </div>
    </div>
    <div class="header-right">
      <div class="clock">
        <span class="time" id="clock-time">--:--:--</span>
        <span id="clock-date">--- -- ----</span>
      </div>
      <?php
        $syncClass = 'ok';
        if ($dbError) $syncClass = 'error';
        elseif ($syncStatus && array_filter($syncStatus, fn($v) => $v === null)) $syncClass = 'stale';
      ?>
      <div class="sync-badge">
        <span class="sync-dot <?= $syncClass === 'error' ? 'error' : ($syncClass === 'stale' ? 'stale' : '') ?>"></span>
        <?= $dbError ? 'DB offline' : 'Live' ?>
      </div>
    </div>
  </header>

  <?php if ($dbError): ?>
  <div class="error-banner">
    <strong>⚠ Database connection error</strong>
    <?= htmlspecialchars($dbError) ?><br>
    Check <code>app/config/config.php</code> and ensure the DB is reachable.
  </div>
  <?php endif; ?>

  <!-- ── Live power (direct dongle read) ──────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Puissance instantanée — temps réel</span>
    <span class="section-line"></span>
    <span id="live-ts" style="font-family:var(--mono);font-size:.68rem;color:var(--muted)">—</span>
  </div>

  <div class="cards cards-2">
    <div class="card amber">
      <div class="card-label"><span class="dot" style="background:var(--amber)"></span>Consommation réseau</div>
      <div class="card-value" id="live-dries-w"><span class="nd">…</span></div>
    </div>
    <div class="card green">
      <div class="card-label"><span class="dot" style="background:var(--green)"></span>Production solaire</div>
      <div class="card-value" id="live-solar-w"><span class="nd">…</span></div>
    </div>
  </div>

  <!-- ── Monthly deltas ────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Consommation — mois en cours</span>
    <span class="section-line"></span>
    <?php if ($deltas): ?>
    <span style="font-family:var(--mono);font-size:.68rem;color:var(--muted)">
      <?= htmlspecialchars(substr($deltas['from'] ?? '', 0, 10)) ?>
      → <?= htmlspecialchars(substr($deltas['to'] ?? '', 0, 10)) ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="cards cards-5">
    <div class="card amber">
      <div class="card-label"><span class="dot" style="background:var(--amber)"></span>Δ Import T1</div>
      <div class="card-value"><?= fmt($deltas['prelev_jour'] ?? null) ?></div>
    </div>
    <div class="card amber">
      <div class="card-label"><span class="dot" style="background:var(--amber);opacity:.6"></span>Δ Import T2</div>
      <div class="card-value"><?= fmt($deltas['prelev_nuit'] ?? null) ?></div>
    </div>
    <div class="card blue">
      <div class="card-label"><span class="dot" style="background:var(--blue)"></span>Δ Export T1</div>
      <div class="card-value"><?= fmt($deltas['injec_jour'] ?? null) ?></div>
    </div>
    <div class="card blue">
      <div class="card-label"><span class="dot" style="background:var(--blue);opacity:.6"></span>Δ Export T2</div>
      <div class="card-value"><?= fmt($deltas['injec_nuit'] ?? null) ?></div>
    </div>
    <div class="card green">
      <div class="card-label"><span class="dot" style="background:var(--green)"></span>Δ Production PV</div>
      <div class="card-value"><?= fmt($deltas['solar'] ?? null) ?></div>
    </div>
  </div>

  <!-- ── Cost estimate ─────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Estimation coûts — mois en cours</span>
    <span class="section-line"></span>
  </div>

  <?php if (!$cost || !($cost['available'] ?? false)): ?>
  <div class="no-tariff">
    <strong style="color:var(--text);margin-bottom:6px;display:block">Aucun tarif configuré</strong>
    <?= htmlspecialchars($cost['reason'] ?? 'Ajoutez un tarif via l\'API ou la section Tarifs.') ?><br><br>
    Exemple via l'API :
    <pre style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:12px;margin-top:10px;font-size:.75rem;overflow-x:auto">POST api.php?action=save_tariff
{
  "energy_type": "electricity",
  "name": "Engie 2025",
  "valid_from": "2025-01-01",
  "lines": {
    "energy_t1": 0.2950,
    "energy_t2": 0.2350,
    "distribution_t1": 0.0821,
    "distribution_t2": 0.0412,
    "distribution_fixed": 0.3082,
    "federal_contribution": 0.0054,
    "injection_t1": 0.0,
    "injection_t2": 0.0,
    "prosumer_annual": 185.0
  }
}</pre>
  </div>

  <?php else:
    $c = $cost['cost'];
  ?>
  <div class="cost-wrap">
    <div class="cost-lines">
      <div class="cost-line">
        <span class="cl-label">Énergie T1 (jour)</span>
        <span class="cl-amount"><?= fmtCost($c['energy_t1']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Énergie T2 (nuit)</span>
        <span class="cl-amount"><?= fmtCost($c['energy_t2']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Distribution T1 (jour)</span>
        <span class="cl-amount"><?= fmtCost($c['distribution_t1']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Distribution T2 (nuit)</span>
        <span class="cl-amount"><?= fmtCost($c['distribution_t2']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Abonnement réseau (<?= $cost['days'] ?> jours)</span>
        <span class="cl-amount"><?= fmtCost($c['distribution_fixed']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Cotisation fédérale</span>
        <span class="cl-amount"><?= fmtCost($c['federal_contribution']) ?></span>
      </div>
      <div class="cost-line">
        <span class="cl-label">Taxe prosumer (prorata <?= $cost['days'] ?>j)</span>
        <span class="cl-amount"><?= fmtCost($c['prosumer_fee']) ?></span>
      </div>
      <div class="cost-line credit">
        <span class="cl-label">Crédit injection ↓</span>
        <span class="cl-amount"><?= fmtCost($c['injection_credit']) ?></span>
      </div>
      <div class="cost-line vat">
        <span class="cl-label">Sous-total HTVA</span>
        <span class="cl-amount"><?= fmtCost($c['subtotal_ex_vat']) ?></span>
      </div>
      <div class="cost-line vat">
        <span class="cl-label">TVA 21%</span>
        <span class="cl-amount"><?= fmtCost($c['vat']) ?></span>
      </div>
      <div class="cost-line total">
        <span class="cl-label">Total TVAC</span>
        <span class="cl-amount"><?= fmtCost($c['total']) ?></span>
      </div>
    </div>

    <div class="cost-total-card">
      <div>
        <div class="cost-total-label">Estimation mois en cours</div>
        <div class="cost-total-amount"><?= fmtCost($c['total']) ?></div>
      </div>
      <div class="cost-total-meta">
        <span>Tarif : <?= htmlspecialchars($cost['tariff_name'] ?? '') ?></span>
        <span>Import : <?= number_format(($deltas['prelev_jour'] ?? 0) + ($deltas['prelev_nuit'] ?? 0), 1) ?> kWh</span>
        <span>Export : <?= number_format(($deltas['injec_jour'] ?? 0) + ($deltas['injec_nuit'] ?? 0), 1) ?> kWh</span>
        <span>Période : <?= $cost['days'] ?> jours</span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Chart ─────────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Historique 30 jours</span>
    <span class="section-line"></span>
    <div style="display:flex;gap:6px">
      <button class="btn btn-ghost" id="btn-30" onclick="loadChart(30)" style="padding:5px 12px;font-size:.7rem">30j</button>
      <button class="btn btn-ghost" id="btn-60" onclick="loadChart(60)" style="padding:5px 12px;font-size:.7rem">60j</button>
      <button class="btn btn-ghost" id="btn-90" onclick="loadChart(90)" style="padding:5px 12px;font-size:.7rem">90j</button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="energyChart"></canvas>
  </div>

  <!-- ── Gas + Sync ────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Gaz</span>
    <span class="section-line"></span>
  </div>

  <div class="gas-grid">
    <div class="gas-history">
      <table>
        <thead><tr><th>Date &amp; heure</th><th>Index (m³)</th><th>Delta</th></tr></thead>
        <tbody id="gas-tbody">
          <?php
          $gasRows = [];
          try { if ($gasRepo) $gasRows = $gasRepo->getLastReadings(6); } catch (\Throwable) {}
          if (empty($gasRows)):
          ?>
          <tr><td colspan="3" class="td-empty">Aucune entrée gaz enregistrée.</td></tr>
          <?php else: foreach ($gasRows as $row): ?>
          <tr>
            <td><?= htmlspecialchars(substr($row['reading_at'], 0, 16)) ?></td>
            <td><?= number_format((float)$row['counter_m3'], 3, '.', ' ') ?></td>
            <td class="td-delta"><?= $row['delta_m3'] !== null ? '+' . number_format($row['delta_m3'], 3, '.', ' ') . ' m³' : '—' ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="gas-form">
      <div style="font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:18px">
        Nouvel index gaz
      </div>
      <?php if ($gasLatest): ?>
      <div style="font-family:var(--mono);font-size:.75rem;color:var(--muted);margin-bottom:14px;padding:8px 12px;background:var(--bg);border-radius:5px;border:1px solid var(--border)">
        Dernier index : <strong style="color:var(--text)"><?= number_format((float)$gasLatest['counter_m3'], 3, '.', ' ') ?> m³</strong>
        le <?= htmlspecialchars(substr($gasLatest['reading_at'], 0, 10)) ?>
      </div>
      <?php endif; ?>
      <div class="form-row">
        <label class="form-label" for="gas-date">Date du relevé</label>
        <input id="gas-date" type="date" class="form-input" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="gas-time">Heure du relevé</label>
        <input id="gas-time" type="time" class="form-input" value="<?= date('H:i') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="gas-value">Index compteur (m³)</label>
        <input id="gas-value" type="number" step="0.001" class="form-input" placeholder="ex. 8523.456" min="0">
      </div>
      <button class="btn btn-amber" id="gas-btn" onclick="submitGas()">Enregistrer</button>
      <div class="form-feedback" id="gas-feedback"></div>
    </div>
  </div>

  <!-- ── Webhook Sync Status ────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Webhook sync — état</span>
    <span class="section-line"></span>
  </div>

  <div class="cards cards-2">
    <div class="card">
      <div class="card-label">Derniers envois EnergyID</div>
      <table class="sync-table">
        <tr><td>Import T1</td><td><?= htmlspecialchars($syncStatus['prelevement_jour'] ?? '—') ?></td></tr>
        <tr><td>Import T2</td><td><?= htmlspecialchars($syncStatus['prelevement_nuit'] ?? '—') ?></td></tr>
        <tr><td>Export T1</td><td><?= htmlspecialchars($syncStatus['injection_jour'] ?? '—') ?></td></tr>
        <tr><td>Export T2</td><td><?= htmlspecialchars($syncStatus['injection_nuit'] ?? '—') ?></td></tr>
        <tr><td>Production PV</td><td><?= htmlspecialchars($syncStatus['production_solaire'] ?? '—') ?></td></tr>
      </table>
    </div>
    <div class="card">
      <div class="card-label">Outils</div>
      <div class="tools-strip">
        <a href="tariffs.php" class="tool-link">
          € Gérer les tarifs
        </a>
        <a href="../tools/migrate_db.php" class="tool-link" target="_blank">
          ⇄ Migration DB
        </a>
        <a href="../tools/conversion_solaire.php?DRY_RUN=1" class="tool-link" target="_blank">
          ☀ Migration Solaire (dry-run)
        </a>
        <a href="../tools/conversion_solaire.php" class="tool-link" target="_blank">
          ☀ Migration Solaire (live)
        </a>
        <a href="api.php?action=sync_status" class="tool-link" target="_blank">
          ⟳ API sync status
        </a>
        <a href="api.php?action=tariffs" class="tool-link" target="_blank">
          € Tarifs JSON
        </a>
      </div>
      <div style="font-family:var(--mono);font-size:.72rem;color:var(--muted);margin-top:20px;line-height:1.6">
        Cron quotidien : <strong style="color:var(--text)">01:15</strong> — cron_daily_webhook.php<br>
        Ingestion horaire : <strong style="color:var(--text)">:00</strong> — cron_hourly.php
      </div>
    </div>
  </div>

  <div style="margin-top:48px;padding-top:20px;border-top:1px solid var(--border);
              font-family:var(--mono);font-size:.68rem;color:var(--muted);display:flex;
              justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span>Manage Energy v2 — Brussels</span>
    <span>PHP <?= PHP_VERSION ?> · <?= date('d/m/Y H:i:s') ?></span>
  </div>

</div><!-- /wrap -->

<script>
// ── Clock ──────────────────────────────────────────────────────────────────
(function clock() {
  const timeEl = document.getElementById('clock-time');
  const dateEl = document.getElementById('clock-date');
  const DAYS   = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
  const MONTHS = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
  function tick() {
    const now = new Date();
    timeEl.textContent = now.toTimeString().slice(0,8);
    dateEl.textContent = `${DAYS[now.getDay()]} ${now.getDate()} ${MONTHS[now.getMonth()]} ${now.getFullYear()}`;
  }
  tick();
  setInterval(tick, 1000);
})();

// ── Chart ──────────────────────────────────────────────────────────────────
let chart = null;

async function loadChart(days = 30) {
  ['btn-30','btn-60','btn-90'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById('btn-' + days);
  if (btn) btn.style.color = 'var(--amber)';

  try {
    const res  = await fetch(`api.php?action=chart_data&days=${days}`);
    const data = await res.json();
    renderChart(data);
  } catch (e) {
    console.warn('Chart load failed:', e);
  }
}

function renderChart(data) {
  const labels    = data.map(d => d.day);
  const importT1  = data.map(d => d.import_t1);
  const importT2  = data.map(d => d.import_t2);
  const exportAll = data.map(d => (d.export_t1 || 0) + (d.export_t2 || 0));
  const solar     = data.map(d => d.solar);

  const ctx = document.getElementById('energyChart').getContext('2d');
  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Import T1 (jour)',
          data: importT1,
          backgroundColor: 'rgba(245,166,35,.7)',
          borderColor: 'rgba(245,166,35,.9)',
          borderWidth: 1,
          stack: 'import',
        },
        {
          label: 'Import T2 (nuit)',
          data: importT2,
          backgroundColor: 'rgba(245,166,35,.3)',
          borderColor: 'rgba(245,166,35,.5)',
          borderWidth: 1,
          stack: 'import',
        },
        {
          label: 'Export (injection)',
          data: exportAll,
          backgroundColor: 'rgba(65,179,245,.55)',
          borderColor: 'rgba(65,179,245,.8)',
          borderWidth: 1,
          stack: 'export',
        },
        {
          label: 'Production PV',
          data: solar,
          type: 'line',
          borderColor: 'rgba(47,213,142,.8)',
          backgroundColor: 'rgba(47,213,142,.08)',
          fill: true,
          tension: .3,
          pointRadius: 2,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: { color: '#556070', font: { family: "'Space Mono'", size: 11 }, boxWidth: 12 },
        },
        tooltip: {
          backgroundColor: '#111318',
          borderColor: '#1f2530',
          borderWidth: 1,
          titleColor: '#c8d0dc',
          bodyColor: '#8899a8',
          callbacks: {
            label: ctx => ` ${ctx.dataset.label}: ${(ctx.raw || 0).toFixed(2)} kWh`,
          },
        },
      },
      scales: {
        x: {
          stacked: true,
          grid: { color: '#1a1f28' },
          ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, maxRotation: 45 },
        },
        y: {
          stacked: false,
          grid: { color: '#1a1f28' },
          ticks: {
            color: '#556070', font: { family: "'Space Mono'", size: 10 },
            callback: v => v + ' kWh',
          },
        },
      },
    },
  });
}

// ── Live dongle polling ────────────────────────────────────────────────────
const LIVE_INTERVAL = 5_000; // 5 secondes

function fmtWatt(v) {
  if (v === undefined || v === null) return '<span class="nd">—</span>';
  const w = Math.abs(parseFloat(v));
  if (w >= 1000) {
    return '<span class="val">' + (w / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span> <span class="unit">kW</span>';
  }
  return '<span class="val">' + w.toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + '</span> <span class="unit">W</span>';
}

async function fetchLive() {
  const syncDot = document.querySelector('.sync-dot');
  try {
    const res  = await fetch('api.php?action=live');
    const data = await res.json();

    document.getElementById('live-ts').textContent = data.timestamp
      ? data.timestamp.slice(0, 19).replace('T', ' ')
      : '—';

    document.getElementById('live-dries-w').innerHTML = data.dries_error
      ? '<span class="nd" title="' + data.dries_error + '">err</span>'
      : fmtWatt(data.dries_w);

    document.getElementById('live-solar-w').innerHTML = data.solar_error
      ? '<span class="nd" title="' + data.solar_error + '">err</span>'
      : fmtWatt(data.solar_w);

    if (syncDot) syncDot.className = (data.dries_error && data.solar_error) ? 'sync-dot error' : 'sync-dot';
  } catch (e) {
    if (syncDot) document.querySelector('.sync-dot').className = 'sync-dot error';
    console.warn('Live fetch failed:', e);
  }
}

fetchLive();
setInterval(fetchLive, LIVE_INTERVAL);



// ── Gas entry ──────────────────────────────────────────────────────────────
async function submitGas() {
  const btn      = document.getElementById('gas-btn');
  const feedback = document.getElementById('gas-feedback');
  const date     = document.getElementById('gas-date').value;
  const time     = document.getElementById('gas-time').value || '00:00';
  const value    = parseFloat(document.getElementById('gas-value').value);

  feedback.textContent = '';
  feedback.className   = 'form-feedback';

  if (!date || isNaN(value) || value <= 0) {
    feedback.textContent = '⚠ Renseigne une date et une valeur valide.';
    feedback.className   = 'form-feedback err';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Envoi…';

  try {
    const res  = await fetch('api.php?action=gas_entry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ counter_m3: value, reading_at: `${date} ${time}:00` }),
    });
    const data = await res.json();

    if (data.ok) {
      feedback.textContent = `✓ Enregistré — ${data.counter_m3} m³ le ${data.saved_at.slice(0, 10)}`;
      feedback.className   = 'form-feedback ok';
      document.getElementById('gas-value').value = '';
      // Reload gas table
      const histRes = await fetch('api.php?action=gas_history');
      const hist    = await histRes.json();
      const tbody   = document.getElementById('gas-tbody');
      if (hist.length) {
        tbody.innerHTML = hist.slice(0, 6).map(r =>
          `<tr>
            <td>${r.reading_at.slice(0, 16)}</td>
            <td>${parseFloat(r.counter_m3).toFixed(3)}</td>
            <td class="td-delta">${r.delta_m3 !== null ? '+' + parseFloat(r.delta_m3).toFixed(3) + ' m³' : '—'}</td>
          </tr>`
        ).join('');
      }
    } else {
      feedback.textContent = '✗ ' + (data.error || 'Erreur inconnue.');
      feedback.className   = 'form-feedback err';
    }
  } catch (e) {
    feedback.textContent = '✗ Erreur réseau.';
    feedback.className   = 'form-feedback err';
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Enregistrer';
  }
}
</script>
</body>
</html>