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
 * @var int                      $initYear
 * @var int                      $initMonth
 * @var int                      $gasInitYear
 * @var int                      $gasInitMonth
 * @var int                      $waterInitYear
 * @var int                      $waterInitMonth
 * @var list<string>             $available
 * @var bool                     $isAdmin
 * @var ?string                  $discordUrl
 * @var ?string                  $adsenseClient Identifiant éditeur AdSense (#185), null si publicité désactivée.
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
    'title'       => 'Manage Energy',
    'css'         => ['assets/css/app-header.css', 'assets/css/dashboard.css'],
    'preconnects' => ['https://cdn.jsdelivr.net'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
<!-- defer : ne bloque pas le parsing du HTML ; s'exécute avant dashboard.js (aussi defer, ordre du document préservé). -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="wrap">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <?= $this->partial('_header', [
      'subtitle'    => 'Dashboard',
      'current'     => 'dashboard',
      'isAdmin'     => $isAdmin ?? false,
      'discordUrl'  => $discordUrl ?? null,
      'available'   => $available,
      'timezone'    => $clockTimezone ?? null,
  ]) ?>

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
        'timezone'          => $timezone ?? 'UTC',
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

  <!-- ── Chart électricité ─────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Historique électricité</span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="btn-30" data-chart-days="30">30j</button>
      <button class="btn btn-ghost btn-xs" id="btn-365" data-chart-days="365">1 an</button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="energyChart"></canvas>
  </div>

  <!-- ── Chart gaz ─────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Historique gaz</span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="gas-btn-30" data-gas-chart-days="30">30j</button>
      <button class="btn btn-ghost btn-xs" id="gas-btn-365" data-gas-chart-days="365">1 an</button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="gasChart"></canvas>
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

  <!-- ── Chart eau ─────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title">Historique eau</span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="water-btn-30" data-water-chart-days="30">30j</button>
      <button class="btn btn-ghost btn-xs" id="water-btn-365" data-water-chart-days="365">1 an</button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="waterChart"></canvas>
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

  <div class="page-footer">
    <span>Manage Energy v3 — Brussels</span>
  </div>

</div><!-- /wrap -->

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/tz.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/dashboard.js') ?>"></script>
</body>
</html>