<?php
/**
 * Template du dashboard principal.
 *
 * @var string|null              $dbError
 * @var array<string,mixed>|null $deltas
 * @var array<string,mixed>|null $cost
 * @var array<string,mixed>|null $gasCostData
 * @var array<string,mixed>|null $gasLatest
 * @var array<string,mixed>|null $waterLatest
 * @var array<string,mixed>|null $waterCostData
 * @var array<string,string|null>|null $syncStatus
 * @var int                      $initYear
 * @var int                      $initMonth
 * @var int                      $gasInitYear
 * @var int                      $gasInitMonth
 * @var int                      $waterInitYear
 * @var int                      $waterInitMonth
 */

// Helpers de présentation (rendu de valeurs numériques / coûts).
if (!function_exists('fmt')) {
    function fmt(mixed $v, int $dec = 3, string $unit = 'kWh'): string
    {
        if ($v === null || $v === false) {
            return '<span class="nd">—</span>';
        }
        return '<span class="val">' . number_format((float) $v, $dec, '.', ' ') . '</span> <span class="unit">' . $unit . '</span>';
    }
}
if (!function_exists('fmtCost')) {
    function fmtCost(mixed $v): string
    {
        if ($v === null) {
            return '<span class="nd">—</span>';
        }
        $sign = (float) $v < 0 ? '−' : '';
        return $sign . number_format(abs((float) $v), 2, '.', ' ') . ' €';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Energy — Brussels</title>
<!-- Anti-FOUC : pose le thème (clair/sombre) avant le 1er rendu (localStorage > système). -->
<script>(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<!-- defer : ne bloque pas le parsing du HTML ; s'exécute avant dashboard.js (aussi defer, ordre du document préservé). -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/dashboard.css') ?>">
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
        <?= $dbError ? 'DB offline' : 'Sync' ?>
      </div>
      <a href="tariffs.php" class="theme-toggle" title="Tarifs" style="text-decoration:none">€</a>
      <a href="account.php" class="theme-toggle" title="Mon compte" style="text-decoration:none">👤</a>
      <span class="langs" style="font-size:.72rem;letter-spacing:.03em"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>" style="text-decoration:none;margin:0 3px<?= $loc === $this->locale() ? ';font-weight:700' : '' ?>"><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
      <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Changer de thème">🌙</button>
    </div>
  </header>

  <?php if ($dbError): ?>
  <div class="error-banner">
    <strong>⚠ Database connection error</strong>
    <?= $this->e($dbError) ?><br>
    Check <code>app/config/config.php</code> and ensure the DB is reachable.
  </div>
  <?php endif; ?>

  <!-- Le widget « temps réel » a été retiré (P4, #47) : le serveur communautaire
       ne peut pas atteindre les compteurs sur le LAN des membres. Les index
       arrivent par push (agent → API) ou saisie manuelle. -->

  <!-- ── Monthly deltas ────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.month') ?></span>
    <span class="section-line"></span>
    <?php if ($deltas): ?>
    <span style="font-family:var(--mono);font-size:.76rem;color:var(--muted)">
      <?= $this->e(substr($deltas['from'] ?? '', 0, 10)) ?>
      → <?= $this->e(substr($deltas['to'] ?? '', 0, 10)) ?>
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
    <span class="section-title">Estimation coûts</span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="nav-prev" title="Mois précédent">&#8592;</button>
      <span class="month-nav-current" id="nav-label">…</span>
      <button class="month-nav-btn" id="nav-next" title="Mois suivant">&#8594;</button>
      <div class="month-nav-mode">
        <button id="mode-month" class="active" onclick="setNavMode('month')">Mois</button>
        <button id="mode-year"  onclick="setNavMode('year')">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview (hidden by default) -->
  <div id="year-overview-wrap" style="display:none">
    <div class="year-overview" id="year-overview-grid"></div>
  </div>

  <!-- Dynamic cost content -->
  <div id="cost-content">
    <div style="font-family:var(--mono);font-size:.85rem;color:var(--muted);padding:24px 0">Chargement…</div>
  </div>

  <script>
    window.__INIT_COST__ = <?= json_encode($cost ?? ['available' => false, 'reason' => 'No data']) ?>;
    window.__INIT_YEAR__  = <?= (int) $initYear ?>;
    window.__INIT_MONTH__ = <?= (int) $initMonth ?>;
    window.__INIT_GAS_COST__ = <?= json_encode($gasCostData ?? ['available' => false, 'reason' => 'No data']) ?>;
    window.__INIT_GAS_YEAR__  = <?= (int) $gasInitYear ?>;
    window.__INIT_GAS_MONTH__ = <?= (int) $gasInitMonth ?>;
    window.__INIT_WATER_COST__ = <?= json_encode($waterCostData ?? ['available' => false, 'reason' => 'No data']) ?>;
    window.__INIT_WATER_YEAR__  = <?= (int) $waterInitYear ?>;
    window.__INIT_WATER_MONTH__ = <?= (int) $waterInitMonth ?>;
  </script>

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
    <span class="section-title"><?= $this->te('dash.gas') ?></span>
    <span class="section-line"></span>
  </div>

  <div class="gas-grid">
    <div class="gas-history">
      <table>
        <thead><tr><th>Date &amp; heure</th><th>Index (m³)</th><th>Delta</th></tr></thead>
        <tbody id="gas-tbody">
          <!-- Rempli via api.php?action=gas_history (dashboard.js) au chargement -->
          <tr><td colspan="3" class="td-empty">Chargement…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="gas-form">
      <div style="font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:18px">
        Nouvel index gaz
      </div>
      <?php if ($gasLatest): ?>
      <div style="font-family:var(--mono);font-size:.95rem;color:var(--muted);margin-bottom:14px;padding:8px 12px;background:var(--bg);border-radius:5px;border:1px solid var(--border)">
        Dernier index : <strong style="color:var(--text)"><?= number_format((float)$gasLatest['counter_m3'], 3, '.', ' ') ?> m³</strong>
        le <?= $this->e(substr($gasLatest['reading_at'], 0, 10)) ?>
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

  <!-- ── Estimation coûts gaz ─────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Estimation coûts gaz</span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="gas-nav-prev" title="Période précédente">&#8592;</button>
      <span class="month-nav-current" id="gas-nav-label">…</span>
      <button class="month-nav-btn" id="gas-nav-next" title="Période suivante">&#8594;</button>
      <div class="month-nav-mode">
        <button id="gas-mode-month" class="active" onclick="setGasNavMode('month')">Mois</button>
        <button id="gas-mode-year"  onclick="setGasNavMode('year')">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview gaz (hidden by default) -->
  <div id="gas-year-overview-wrap" style="display:none">
    <div class="year-overview" id="gas-year-overview-grid"></div>
  </div>

  <!-- Dynamic gas cost content -->
  <div id="gas-cost-content">
    <div style="font-family:var(--mono);font-size:.85rem;color:var(--muted);padding:24px 0">Chargement…</div>
  </div>

  <!-- ── Eau ──────────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.water') ?></span>
    <span class="section-line"></span>
  </div>

  <div class="gas-grid">
    <div class="gas-history">
      <table>
        <thead><tr><th>Date &amp; heure</th><th>Index (m³)</th><th>Delta</th></tr></thead>
        <tbody id="water-tbody">
          <!-- Rempli via api.php?action=water_history (dashboard.js) au chargement -->
          <tr><td colspan="3" class="td-empty">Chargement…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="gas-form">
      <div style="font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:18px">
        Nouvel index eau
      </div>
      <?php if ($waterLatest): ?>
      <div style="font-family:var(--mono);font-size:.95rem;color:var(--muted);margin-bottom:14px;padding:8px 12px;background:var(--bg);border-radius:5px;border:1px solid var(--border)">
        Dernier index : <strong style="color:var(--text)"><?= number_format((float)$waterLatest['counter_m3'], 3, '.', ' ') ?> m³</strong>
        le <?= $this->e(substr($waterLatest['reading_at'], 0, 10)) ?>
      </div>
      <?php endif; ?>
      <div class="form-row">
        <label class="form-label" for="water-date">Date du relevé</label>
        <input id="water-date" type="date" class="form-input" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="water-time">Heure du relevé</label>
        <input id="water-time" type="time" class="form-input" value="<?= date('H:i') ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="water-value">Index compteur (m³)</label>
        <input id="water-value" type="number" step="0.001" class="form-input" placeholder="ex. 1234.567" min="0">
      </div>
      <button class="btn btn-amber" id="water-btn" onclick="submitWater()">Enregistrer</button>
      <div class="form-feedback" id="water-feedback"></div>
    </div>
  </div>

  <!-- ── Consommation eau ─────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Consommation eau</span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="water-nav-prev" title="Mois précédent">&#8592;</button>
      <span class="month-nav-current" id="water-nav-label">…</span>
      <button class="month-nav-btn" id="water-nav-next" title="Mois suivant">&#8594;</button>
      <div class="month-nav-mode">
        <button id="water-mode-month" class="active" onclick="setWaterNavMode('month')">Mois</button>
        <button id="water-mode-year"  onclick="setWaterNavMode('year')">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview eau (hidden by default) -->
  <div id="water-year-overview-wrap" style="display:none">
    <div class="year-overview" id="water-year-overview-grid"></div>
  </div>

  <!-- Water consumption content (volume m³, pas de coût) -->
  <div id="water-cost-content">
    <div style="font-family:var(--mono);font-size:.85rem;color:var(--muted);padding:24px 0">Chargement…</div>
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
        <tr><td>Import T1</td><td><?= $this->e($syncStatus['prelevement_jour'] ?? '—') ?></td></tr>
        <tr><td>Import T2</td><td><?= $this->e($syncStatus['prelevement_nuit'] ?? '—') ?></td></tr>
        <tr><td>Export T1</td><td><?= $this->e($syncStatus['injection_jour'] ?? '—') ?></td></tr>
        <tr><td>Export T2</td><td><?= $this->e($syncStatus['injection_nuit'] ?? '—') ?></td></tr>
        <tr><td>Production PV</td><td><?= $this->e($syncStatus['production_solaire'] ?? '—') ?></td></tr>
        <tr><td>Gaz</td><td><?= $this->e($syncStatus['gaz_index'] ?? '—') ?></td></tr>
        <tr><td>Eau</td><td><?= $this->e($syncStatus['water_index'] ?? '—') ?></td></tr>
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
        <a href="api.php?action=sync_status" class="tool-link" target="_blank">
          ⟳ API sync status
        </a>
        <a href="api.php?action=tariffs" class="tool-link" target="_blank">
          € Tarifs JSON
        </a>
      </div>
    </div>
  </div>

  <div style="margin-top:48px;padding-top:20px;border-top:1px solid var(--border);
              font-family:var(--mono);font-size:.76rem;color:var(--muted);display:flex;
              justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span>Manage Energy v2 — Brussels</span>
    <span>PHP <?= PHP_VERSION ?> · <?= date('d/m/Y H:i:s') ?></span>
  </div>

</div><!-- /wrap -->

<script defer src="<?= \App\Support\Assets::url('assets/js/theme.js') ?>"></script>
<script>window.APP_LOCALE=<?= json_encode($this->locale()) ?>;</script>
<script defer src="<?= \App\Support\Assets::url('assets/js/dashboard.js') ?>"></script>
</body>
</html>