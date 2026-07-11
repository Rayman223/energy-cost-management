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
 * @var list<string>             $available
 * @var bool                     $isAdmin
 */

// Helper de présentation : valeur numérique localisée via le Formatter (closure
// liée à $this = View, pour router le formatage par $this->num()).
$fmt = function (mixed $v, int $dec = 3, string $unit = 'kWh'): string {
    if ($v === null || $v === false) {
        return '<span class="nd">—</span>';
    }
    return '<span class="val">' . $this->num((float) $v, $dec) . '</span> <span class="unit">' . $unit . '</span>';
};
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title'       => 'Manage Energy — Brussels',
    'css'         => ['assets/css/dashboard.css'],
    'preconnects' => ['https://cdn.jsdelivr.net'],
]) ?>
<!-- defer : ne bloque pas le parsing du HTML ; s'exécute avant dashboard.js (aussi defer, ordre du document préservé). -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="wrap">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <header>
    <div class="logo">
      <div class="logo-icon">⚡</div>
      <div>
        <div class="logo-text">Manage Energy</div>
        <div class="logo-sub">Dashboard</div>
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
      <a href="meter-readings.php" class="theme-toggle" title="<?= $this->te('nav.meter_readings') ?>">📝</a>
      <?php if (!empty($isAdmin)): ?><a href="admin.php" class="theme-toggle" title="<?= $this->te('admin.title') ?>">🛡</a><?php endif; ?>
      <a href="tariffs.php" class="theme-toggle" title="Tarifs">€</a>
      <a href="account.php" class="theme-toggle" title="Mon compte">👤</a>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
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
    <span class="deltas-range">
      <?= $this->e(substr($deltas['from'] ?? '', 0, 10)) ?>
      → <?= $this->e(substr($deltas['to'] ?? '', 0, 10)) ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="cards cards-5">
    <div class="card amber">
      <div class="card-label"><span class="dot dot--amber"></span>Δ Import T1</div>
      <div class="card-value"><?= $fmt($deltas['prelev_jour'] ?? null) ?></div>
    </div>
    <div class="card amber">
      <div class="card-label"><span class="dot dot--amber-dim"></span>Δ Import T2</div>
      <div class="card-value"><?= $fmt($deltas['prelev_nuit'] ?? null) ?></div>
    </div>
    <div class="card blue">
      <div class="card-label"><span class="dot dot--blue"></span>Δ Export T1</div>
      <div class="card-value"><?= $fmt($deltas['injec_jour'] ?? null) ?></div>
    </div>
    <div class="card blue">
      <div class="card-label"><span class="dot dot--blue-dim"></span>Δ Export T2</div>
      <div class="card-value"><?= $fmt($deltas['injec_nuit'] ?? null) ?></div>
    </div>
    <div class="card green">
      <div class="card-label"><span class="dot dot--green"></span>Δ Production PV</div>
      <div class="card-value"><?= $fmt($deltas['solar'] ?? null) ?></div>
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
        <button id="mode-month" class="active" data-nav-mode="month">Mois</button>
        <button id="mode-year"  data-nav-mode="year">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview (hidden by default) -->
  <div id="year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="year-overview-grid"></div>
  </div>

  <!-- Dynamic cost content -->
  <div id="cost-content">
    <div class="async-note">Chargement…</div>
  </div>

  <?php
    // Libellés des composantes tarifaires (catalogue) + groupes, pour le rendu
    // générique du détail de coût (dashboard.js lit cost.lines par clé/groupe).
    $tariffLineLabels = [];
    foreach (['electricity', 'gas', 'water'] as $catEnergy) {
        foreach (\App\Domain\TariffLineCatalog::forType($catEnergy) as $catKey => $catDef) {
            $tariffLineLabels[$catKey] = $catDef['label'];
        }
    }
    $tariffGroupLabels = [
        'energy'       => $this->t('tariffs.group_energy'),
        'distribution' => $this->t('tariffs.group_distribution'),
        'fixed'        => $this->t('tariffs.group_fixed'),
        'taxes'        => $this->t('tariffs.group_taxes'),
        'injection'    => $this->t('tariffs.group_injection'),
    ];

    // État serveur initial transmis à dashboard.js via un data block JSON. Un
    // <script type="application/json"> n'est jamais exécuté → hors script-src,
    // donc compatible avec la CSP durcie (sans nonce). #98
    $dashboardData = [
        'locale'            => $this->locale(),
        'currency'          => $currency ?? 'EUR',
        'initCost'          => $cost ?? ['available' => false, 'reason' => 'No data'],
        'initYear'          => (int) $initYear,
        'initMonth'         => (int) $initMonth,
        'initGasCost'       => $gasCostData ?? ['available' => false, 'reason' => 'No data'],
        'initGasYear'       => (int) $gasInitYear,
        'initGasMonth'      => (int) $gasInitMonth,
        'initWaterCost'     => $waterCostData ?? ['available' => false, 'reason' => 'No data'],
        'initWaterYear'     => (int) $waterInitYear,
        'initWaterMonth'    => (int) $waterInitMonth,
        'tariffLineLabels'  => $tariffLineLabels,
        'tariffGroupLabels' => $tariffGroupLabels,
    ];
  ?>
  <script type="application/json" id="dashboard-data"><?= json_encode($dashboardData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>

  <!-- ── Chart ─────────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Historique 30 jours</span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="btn-30" data-chart-days="30">30j</button>
      <button class="btn btn-ghost btn-xs" id="btn-60" data-chart-days="60">60j</button>
      <button class="btn btn-ghost btn-xs" id="btn-90" data-chart-days="90">90j</button>
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

  <div class="gas-grid gas-grid-history-only">
    <div class="gas-history">
      <table>
        <thead><tr><th>Date &amp; heure</th><th>Index (m³)</th><th>Delta</th></tr></thead>
        <tbody id="gas-tbody">
          <!-- Rempli via api.php?action=gas_history (dashboard.js) au chargement -->
          <tr><td colspan="3" class="td-empty">Chargement…</td></tr>
        </tbody>
      </table>
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
        <button id="gas-mode-month" class="active" data-gas-nav-mode="month">Mois</button>
        <button id="gas-mode-year"  data-gas-nav-mode="year">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview gaz (hidden by default) -->
  <div id="gas-year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="gas-year-overview-grid"></div>
  </div>

  <!-- Dynamic gas cost content -->
  <div id="gas-cost-content">
    <div class="async-note">Chargement…</div>
  </div>

  <!-- ── Eau ──────────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.water') ?></span>
    <span class="section-line"></span>
  </div>

  <div class="gas-grid gas-grid-history-only">
    <div class="gas-history">
      <table>
        <thead><tr><th>Date &amp; heure</th><th>Index (m³)</th><th>Delta</th></tr></thead>
        <tbody id="water-tbody">
          <!-- Rempli via api.php?action=water_history (dashboard.js) au chargement -->
          <tr><td colspan="3" class="td-empty">Chargement…</td></tr>
        </tbody>
      </table>
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
        <button id="water-mode-month" class="active" data-water-nav-mode="month">Mois</button>
        <button id="water-mode-year"  data-water-nav-mode="year">Année</button>
      </div>
    </div>
  </div>

  <!-- Year overview eau (hidden by default) -->
  <div id="water-year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="water-year-overview-grid"></div>
  </div>

  <!-- Water consumption content (volume m³, pas de coût) -->
  <div id="water-cost-content">
    <div class="async-note">Chargement…</div>
  </div>

  <!-- ── Webhook Sync Status ────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Webhook sync — état</span>
    <span class="section-line"></span>
  </div>

  <div class="page-footer">
    <span>Manage Energy v3 — Brussels</span>
  </div>

</div><!-- /wrap -->

<script defer src="<?= \App\Support\Assets::url('assets/js/dashboard.js') ?>"></script>
</body>
</html>