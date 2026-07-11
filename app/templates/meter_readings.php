<?php
/**
 * @var string|null $dbError
 * @var array<string,mixed>|null $gasLatest
 * @var array<string,mixed>|null $waterLatest
 * @var list<string> $available
 */
$today = date('Y-m-d');
$now = date('H:i');
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->te('meter.title') ?> — Manage Energy</title>
<script nonce="<?= $this->e(\App\Http\SecurityHeaders::nonce()) ?>">(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/dashboard.css') ?>">
</head>
<body>
<div class="wrap">
  <header>
    <div class="logo">
      <div class="logo-icon">📝</div>
      <div>
        <div class="logo-text"><?= $this->te('meter.title') ?></div>
        <div class="logo-sub"><?= $this->te('meter.subtitle') ?></div>
      </div>
    </div>
    <div class="header-right">
      <a href="<?= $this->url() ?>" class="tool-link"><?= $this->te('nav.back_dashboard') ?></a>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>" style="text-decoration:none;margin:0 3px<?= $loc === $this->locale() ? ';font-weight:700' : '' ?>"><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
      <button type="button" class="theme-toggle" id="theme-toggle" aria-label="<?= $this->te('common.theme') ?>">🌙</button>
    </div>
  </header>

  <?php if ($dbError): ?>
  <div class="error-banner"><strong>⚠ <?= $this->te('dash.db_offline') ?></strong><?= $this->e($dbError) ?></div>
  <?php endif; ?>

  <div class="section-header">
    <span class="section-title"><?= $this->te('meter.electricity_title') ?></span>
    <span class="section-line"></span>
  </div>
  <div class="gas-form">
    <div class="cards cards-2">
      <div class="form-row">
        <label class="form-label" for="electricity-date"><?= $this->te('meter.reading_date') ?></label>
        <input id="electricity-date" type="date" class="form-input" value="<?= $this->e($today) ?>">
      </div>
      <div class="form-row">
        <label class="form-label" for="electricity-time"><?= $this->te('meter.reading_time') ?></label>
        <input id="electricity-time" type="time" class="form-input" value="<?= $this->e($now) ?>">
      </div>
    </div>
    <div class="cards cards-5">
      <?php foreach ([
        'import_t1' => 'meter.import_t1',
        'import_t2' => 'meter.import_t2',
        'export_t1' => 'meter.export_t1',
        'export_t2' => 'meter.export_t2',
        'production' => 'meter.production',
      ] as $key => $label): ?>
      <div class="form-row">
        <label class="form-label" for="electricity-<?= $this->e($key) ?>"><?= $this->te($label) ?></label>
        <input id="electricity-<?= $this->e($key) ?>" type="number" step="0.001" min="0" class="form-input" placeholder="kWh">
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-amber" id="electricity-btn"><?= $this->te('common.save') ?></button>
    <div class="form-feedback" id="electricity-feedback"></div>
  </div>

  <div class="gas-history gas-history-wide">
    <table>
      <thead><tr>
        <th><?= $this->te('meter.date_time') ?></th>
        <th><?= $this->te('meter.import_t1') ?> (kWh)</th>
        <th><?= $this->te('meter.import_t2') ?> (kWh)</th>
        <th><?= $this->te('meter.export_t1') ?> (kWh)</th>
        <th><?= $this->te('meter.export_t2') ?> (kWh)</th>
        <th><?= $this->te('meter.production') ?> (kWh)</th>
      </tr></thead>
      <tbody id="electricity-tbody"><tr><td colspan="6" class="td-empty">…</td></tr></tbody>
    </table>
  </div>

  <div class="section-header"><span class="section-title"><?= $this->te('dash.gas') ?></span><span class="section-line"></span></div>
  <div class="gas-grid">
    <div class="gas-form">
      <?php if ($gasLatest): ?><div class="card-sub"><?= $this->te('meter.latest') ?> : <strong><?= $this->num((float) $gasLatest['counter_m3'], 3) ?> m³</strong></div><?php endif; ?>
      <div class="cards cards-2"><div class="form-row"><label class="form-label" for="gas-date"><?= $this->te('meter.reading_date') ?></label><input id="gas-date" type="date" class="form-input" value="<?= $this->e($today) ?>"></div><div class="form-row"><label class="form-label" for="gas-time"><?= $this->te('meter.reading_time') ?></label><input id="gas-time" type="time" class="form-input" value="<?= $this->e($now) ?>"></div></div>
      <div class="form-row"><label class="form-label" for="gas-value"><?= $this->te('meter.counter_m3') ?></label><input id="gas-value" type="number" step="0.001" min="0" class="form-input" placeholder="8523.456"></div>
      <button class="btn btn-amber" id="gas-btn"><?= $this->te('common.save') ?></button><div class="form-feedback" id="gas-feedback"></div>
    </div>
    <div class="gas-history"><table><thead><tr><th><?= $this->te('meter.date_time') ?></th><th><?= $this->te('meter.index_m3') ?></th><th>Delta</th></tr></thead><tbody id="gas-tbody"><tr><td colspan="3" class="td-empty">…</td></tr></tbody></table></div>
  </div>

  <div class="section-header"><span class="section-title"><?= $this->te('dash.water') ?></span><span class="section-line"></span></div>
  <div class="gas-grid">
    <div class="gas-form">
      <?php if ($waterLatest): ?><div class="card-sub"><?= $this->te('meter.latest') ?> : <strong><?= $this->num((float) $waterLatest['counter_m3'], 3) ?> m³</strong></div><?php endif; ?>
      <div class="cards cards-2"><div class="form-row"><label class="form-label" for="water-date"><?= $this->te('meter.reading_date') ?></label><input id="water-date" type="date" class="form-input" value="<?= $this->e($today) ?>"></div><div class="form-row"><label class="form-label" for="water-time"><?= $this->te('meter.reading_time') ?></label><input id="water-time" type="time" class="form-input" value="<?= $this->e($now) ?>"></div></div>
      <div class="form-row"><label class="form-label" for="water-value"><?= $this->te('meter.counter_m3') ?></label><input id="water-value" type="number" step="0.001" min="0" class="form-input" placeholder="1234.567"></div>
      <button class="btn btn-amber" id="water-btn"><?= $this->te('common.save') ?></button><div class="form-feedback" id="water-feedback"></div>
    </div>
    <div class="gas-history"><table><thead><tr><th><?= $this->te('meter.date_time') ?></th><th><?= $this->te('meter.index_m3') ?></th><th>Delta</th></tr></thead><tbody id="water-tbody"><tr><td colspan="3" class="td-empty">…</td></tr></tbody></table></div>
  </div>
</div>
<script nonce="<?= $this->e(\App\Http\SecurityHeaders::nonce()) ?>">
window.METER_I18N = <?= json_encode([
    'invalidUtility' => $this->t('meter.invalid_utility'),
    'invalidElectricity' => $this->t('meter.invalid_electricity'),
    'sending' => $this->t('meter.sending'),
    'saved' => $this->t('meter.saved'),
    'unknownError' => $this->t('meter.unknown_error'),
    'networkError' => $this->t('meter.network_error'),
    'emptyGas' => $this->t('meter.empty_gas'),
    'emptyWater' => $this->t('meter.empty_water'),
    'emptyElectricity' => $this->t('meter.empty_electricity'),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script defer src="<?= \App\Support\Assets::url('assets/js/theme.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/meter-readings.js') ?>"></script>
</body>
</html>
