<?php
/**
 * Template du dashboard principal.
 *
 * @var string|null              $dbError
 * @var array<string,mixed>|null $deltas
 * @var list<array<string,mixed>> $cards
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
    return '<span class="val">' . $this->num((float) $v, $dec) . '</span> <span class="unit">' . $this->e($unit) . '</span>';
};

// Badge de variation vs mois précédent (#215). Le sens « favorable » dépend de
// la nature de la card : baisse = bien pour une consommation, mal pour une
// production/injection.
$deltaBadge = function (?float $pct, bool $lowerIsBetter, bool $isNew = false): string {
    // Mois de référence connu mais à zéro : pas de pourcentage possible, on
    // signale quand même le démarrage de la consommation.
    if ($pct === null && $isNew) {
        return '<div class="card-delta card-delta--' . ($lowerIsBetter ? 'bad' : 'good') . '">'
            . '▲ <span class="card-delta-ref">' . $this->te('dash.card.new') . '</span></div>';
    }
    if ($pct === null) {
        return '';
    }
    if ($pct > 0.0) {
        $arrow = '▲';
        $tone  = $lowerIsBetter ? 'bad' : 'good';
    } elseif ($pct < 0.0) {
        $arrow = '▼';
        $tone  = $lowerIsBetter ? 'good' : 'bad';
    } else {
        $arrow = '=';
        $tone  = 'flat';
    }

    return '<div class="card-delta card-delta--' . $tone . '">'
        . $arrow . ' ' . ($pct > 0.0 ? '+' : '') . $this->num($pct, 1) . ' % '
        . '<span class="card-delta-ref">' . $this->te('dash.card.vs_prev') . '</span></div>';
};
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title'       => 'Manage Energy',
    'css'         => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/dashboard.css'],
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
      'subtitle'    => $this->t('dash.subtitle'),
      'current'     => 'dashboard',
      'isAdmin'     => $isAdmin ?? false,
      'discordUrl'  => $discordUrl ?? null,
      'available'   => $available,
      'timezone'    => $clockTimezone ?? null,
  ]) ?>

  <?php if ($dbError): ?>
  <div class="error-banner">
    <strong>⚠ <?= $this->te('dash.db_offline') ?></strong>
    <?= $this->e($dbError) ?><br>
    <?= $this->te('dash.db_error_hint', ['file' => 'app/config/config.php']) ?>
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

  <!-- Cards composées par DashboardCardsService : la card solaire est absente de
       $cards (et non masquée en CSS) quand aucune production n'est disponible. -->
  <div class="cards cards-auto">
    <?php foreach ($cards as $card): ?>
    <div class="card <?= $this->e((string) $card['tone']) ?>">
      <div class="card-label"><span class="dot <?= $this->e((string) $card['dot']) ?>"></span><?= $this->te((string) $card['label_key']) ?></div>
      <div class="card-value"><?= $fmt($card['value'], (int) $card['decimals'], (string) $card['unit']) ?></div>
      <?= $deltaBadge(
          $card['delta_pct'] === null ? null : (float) $card['delta_pct'],
          (bool) $card['lower_is_better'],
          (bool) $card['is_new'],
      ) ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Cost estimate ─────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.cost.electricity') ?></span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="nav-prev" title="<?= $this->te('dash.nav.prev_month') ?>">&#8592;</button>
      <span class="month-nav-current" id="nav-label">…</span>
      <button class="month-nav-btn" id="nav-next" title="<?= $this->te('dash.nav.next_month') ?>">&#8594;</button>
      <div class="month-nav-mode">
        <button id="mode-month" class="active" data-nav-mode="month"><?= $this->te('dash.nav.month') ?></button>
        <button id="mode-year"  data-nav-mode="year"><?= $this->te('dash.nav.year') ?></button>
      </div>
    </div>
  </div>

  <!-- Year overview (hidden by default) -->
  <div id="year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="year-overview-grid"></div>
  </div>

  <!-- Dynamic cost content -->
  <div id="cost-content">
    <div class="async-note"><?= $this->te('common.loading') ?></div>
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
        // Sous-catalogue des libellés rendus côté client (#223). Extrait par
        // préfixe : aucune clé à énumérer ici, donc pas de désynchronisation
        // possible entre dashboard.js et les catalogues. Les mêmes clés servent
        // au template et au script, sans doublon.
        'i18n'              => $this->translations('dash.', 'common.'),
    ];
  ?>
  <script type="application/json" id="dashboard-data"><?= json_encode($dashboardData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>

  <!-- ── Chart électricité ─────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.history.electricity') ?></span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="btn-30" data-chart-days="30"><?= $this->te('dash.range.30d') ?></button>
      <button class="btn btn-ghost btn-xs" id="btn-365" data-chart-days="365"><?= $this->te('dash.range.1y') ?></button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="energyChart"></canvas>
  </div>

  <!-- ── Estimation coûts gaz ─────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.cost.gas') ?></span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="gas-nav-prev" title="<?= $this->te('dash.nav.prev_period') ?>">&#8592;</button>
      <span class="month-nav-current" id="gas-nav-label">…</span>
      <button class="month-nav-btn" id="gas-nav-next" title="<?= $this->te('dash.nav.next_period') ?>">&#8594;</button>
      <div class="month-nav-mode">
        <button id="gas-mode-month" class="active" data-gas-nav-mode="month"><?= $this->te('dash.nav.month') ?></button>
        <button id="gas-mode-year"  data-gas-nav-mode="year"><?= $this->te('dash.nav.year') ?></button>
      </div>
    </div>
  </div>

  <!-- Year overview gaz (hidden by default) -->
  <div id="gas-year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="gas-year-overview-grid"></div>
  </div>

  <!-- Dynamic gas cost content -->
  <div id="gas-cost-content">
    <div class="async-note"><?= $this->te('common.loading') ?></div>
  </div>

  <!-- ── Chart gaz ─────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.history.gas') ?></span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="gas-btn-12" data-gas-chart-months="12"><?= $this->te('dash.range.12m') ?></button>
      <button class="btn btn-ghost btn-xs" id="gas-btn-24" data-gas-chart-months="24"><?= $this->te('dash.range.24m') ?></button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="gasChart"></canvas>
  </div>

  <!-- ── Consommation eau ─────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.consumption.water') ?></span>
    <span class="section-line"></span>
    <div class="month-nav">
      <button class="month-nav-btn" id="water-nav-prev" title="<?= $this->te('dash.nav.prev_month') ?>">&#8592;</button>
      <span class="month-nav-current" id="water-nav-label">…</span>
      <button class="month-nav-btn" id="water-nav-next" title="<?= $this->te('dash.nav.next_month') ?>">&#8594;</button>
      <div class="month-nav-mode">
        <button id="water-mode-month" class="active" data-water-nav-mode="month"><?= $this->te('dash.nav.month') ?></button>
        <button id="water-mode-year"  data-water-nav-mode="year"><?= $this->te('dash.nav.year') ?></button>
      </div>
    </div>
  </div>

  <!-- Year overview eau (hidden by default) -->
  <div id="water-year-overview-wrap" class="is-hidden">
    <div class="year-overview" id="water-year-overview-grid"></div>
  </div>

  <!-- Water consumption content (volume m³, pas de coût) -->
  <div id="water-cost-content">
    <div class="async-note"><?= $this->te('common.loading') ?></div>
  </div>

  <!-- ── Chart eau ─────────────────────────────────────────────────────── -->
  <div class="section-header">
    <span class="section-title"><?= $this->te('dash.history.water') ?></span>
    <span class="section-line"></span>
    <div class="btn-row">
      <button class="btn btn-ghost btn-xs" id="water-btn-12" data-water-chart-months="12"><?= $this->te('dash.range.12m') ?></button>
      <button class="btn btn-ghost btn-xs" id="water-btn-24" data-water-chart-months="24"><?= $this->te('dash.range.24m') ?></button>
    </div>
  </div>
  <div class="chart-card">
    <canvas id="waterChart"></canvas>
  </div>

  <div class="page-footer">
    <span>Manage Energy v3 — Brussels</span>
  </div>

</div><!-- /wrap -->

<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/tz.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/dashboard.js') ?>"></script>
</body>
</html>