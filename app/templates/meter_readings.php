<?php
/**
 * @var string|null $dbError
 * @var array<string,mixed>|null $gasLatest
 * @var array<string,mixed>|null $waterLatest
 * @var list<string> $available
 * @var ?string $discordUrl
 * @var ?string $adsenseClient Identifiant éditeur AdSense (#185), null si publicité désactivée.
 */
$today = date('Y-m-d');
$now = date('H:i');
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('meter.title') . ' — Manage Energy',
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/dashboard.css', 'assets/css/confirm.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <?= $this->partial('_header', [
      'subtitle'    => $this->t('meter.subtitle'),
      'current'     => 'meter-readings',
      'isAdmin'     => $isAdmin ?? false,
      'discordUrl'  => $discordUrl ?? null,
      'available'   => $available,
      'timezone'    => $clockTimezone ?? null,
  ]) ?>

  <?php if ($dbError): ?>
  <div class="error-banner"><strong>⚠ <?= $this->te('dash.db_offline') ?></strong><?= $this->e($dbError) ?></div>
  <?php endif; ?>

  <div class="section-header">
    <span class="section-title"><?= $this->te('meter.electricity_title') ?></span>
    <span class="section-line"></span>
    <button type="button" class="btn btn-red btn-sm" id="electricity-delete-all"><?= $this->te('meter.delete_all') ?></button>
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
        <th aria-label="<?= $this->e($this->t('meter.actions')) ?>"></th>
      </tr></thead>
      <tbody id="electricity-tbody"><tr><td colspan="7" class="td-empty">…</td></tr></tbody>
    </table>
    <div class="form-feedback" id="electricity-del-feedback"></div>
  </div>
  <?= $this->partial('_pager', ['id' => 'electricity-pager']) ?>

  <div class="section-header"><span class="section-title"><?= $this->te('dash.gas') ?></span><span class="section-line"></span><button type="button" class="btn btn-red btn-sm" id="gas-delete-all"><?= $this->te('meter.delete_all') ?></button></div>
  <div class="gas-grid">
    <div class="gas-form">
      <?php if ($gasLatest): ?><div class="card-sub"><?= $this->te('meter.latest') ?> : <strong><?= $this->num((float) $gasLatest['counter_m3'], 3) ?> m³</strong></div><?php endif; ?>
      <div class="cards cards-2"><div class="form-row"><label class="form-label" for="gas-date"><?= $this->te('meter.reading_date') ?></label><input id="gas-date" type="date" class="form-input" value="<?= $this->e($today) ?>"></div><div class="form-row"><label class="form-label" for="gas-time"><?= $this->te('meter.reading_time') ?></label><input id="gas-time" type="time" class="form-input" value="<?= $this->e($now) ?>"></div></div>
      <div class="form-row"><label class="form-label" for="gas-value"><?= $this->te('meter.counter_m3') ?></label><input id="gas-value" type="number" step="0.001" min="0" class="form-input" placeholder="8523.456"></div>
      <button class="btn btn-amber" id="gas-btn"><?= $this->te('common.save') ?></button><div class="form-feedback" id="gas-feedback"></div>
    </div>
    <div>
      <div class="gas-history"><table><thead><tr><th><?= $this->te('meter.date_time') ?></th><th><?= $this->te('meter.index_m3') ?></th><th>Delta</th><th aria-label="<?= $this->e($this->t('meter.actions')) ?>"></th></tr></thead><tbody id="gas-tbody"><tr><td colspan="4" class="td-empty">…</td></tr></tbody></table><div class="form-feedback" id="gas-del-feedback"></div></div>
      <?= $this->partial('_pager', ['id' => 'gas-pager']) ?>
    </div>
  </div>

  <div class="section-header"><span class="section-title"><?= $this->te('dash.water') ?></span><span class="section-line"></span><button type="button" class="btn btn-red btn-sm" id="water-delete-all"><?= $this->te('meter.delete_all') ?></button></div>
  <div class="gas-grid">
    <div class="gas-form">
      <?php if ($waterLatest): ?><div class="card-sub"><?= $this->te('meter.latest') ?> : <strong><?= $this->num((float) $waterLatest['counter_m3'], 3) ?> m³</strong></div><?php endif; ?>
      <div class="cards cards-2"><div class="form-row"><label class="form-label" for="water-date"><?= $this->te('meter.reading_date') ?></label><input id="water-date" type="date" class="form-input" value="<?= $this->e($today) ?>"></div><div class="form-row"><label class="form-label" for="water-time"><?= $this->te('meter.reading_time') ?></label><input id="water-time" type="time" class="form-input" value="<?= $this->e($now) ?>"></div></div>
      <div class="form-row"><label class="form-label" for="water-value"><?= $this->te('meter.counter_m3') ?></label><input id="water-value" type="number" step="0.001" min="0" class="form-input" placeholder="1234.567"></div>
      <button class="btn btn-amber" id="water-btn"><?= $this->te('common.save') ?></button><div class="form-feedback" id="water-feedback"></div>
    </div>
    <div>
      <div class="gas-history"><table><thead><tr><th><?= $this->te('meter.date_time') ?></th><th><?= $this->te('meter.index_m3') ?></th><th>Delta</th><th aria-label="<?= $this->e($this->t('meter.actions')) ?>"></th></tr></thead><tbody id="water-tbody"><tr><td colspan="4" class="td-empty">…</td></tr></tbody></table><div class="form-feedback" id="water-del-feedback"></div></div>
      <?= $this->partial('_pager', ['id' => 'water-pager']) ?>
    </div>
  </div>
</div>
<?php
    // État serveur (libellés i18n) transmis à meter-readings.js via un data block
    // JSON. Un <script type="application/json"> n'est jamais exécuté → hors
    // script-src, donc compatible avec la CSP durcie (sans nonce). #98
    $meterData = [
        // Fuseau d'affichage de l'utilisateur : les reading_at (UTC) sont
        // reconvertis vers ce fuseau côté client, et la saisie locale y est
        // interprétée avant conversion en UTC pour l'envoi.
        'timezone' => $timezone ?? 'UTC',
        'locale'   => $this->locale(),
        'i18n' => [
            'invalidUtility' => $this->t('meter.invalid_utility'),
            'invalidElectricity' => $this->t('meter.invalid_electricity'),
            'sending' => $this->t('meter.sending'),
            'saved' => $this->t('meter.saved'),
            'unknownError' => $this->t('meter.unknown_error'),
            'networkError' => $this->t('meter.network_error'),
            'emptyGas' => $this->t('meter.empty_gas'),
            'emptyWater' => $this->t('meter.empty_water'),
            'emptyElectricity' => $this->t('meter.empty_electricity'),
            'delete' => $this->t('meter.delete'),
            'deleteConfirm' => $this->t('meter.delete_confirm'),
            'deleteAll' => $this->t('meter.delete_all'),
            'deleteAllConfirm' => $this->t('meter.delete_all_confirm'),
            'deleted' => $this->t('meter.deleted'),
            'deleteNone' => $this->t('meter.delete_none'),
            'deleteError' => $this->t('meter.delete_error'),
            // Statut de pagination (#257) : jetons {page} / {pages} / {total}
            // substitués côté client, comme Translator::t() côté PHP.
            'pageStatus' => $this->t('meter.page_status'),
            'savedElsewhere' => $this->t('meter.saved_elsewhere'),
        ],
    ];
?>
<script type="application/json" id="meter-data"><?= json_encode($meterData, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/tz.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/meter-readings.js') ?>"></script>
</body>
</html>
