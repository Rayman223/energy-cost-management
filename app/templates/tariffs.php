<?php

use App\Domain\TariffGrid;

/**
 * Template de gestion des grilles tarifaires.
 *
 * @var string|null                                   $error
 * @var string|null                                   $success
 * @var TariffGrid[]                                  $elecGrids
 * @var TariffGrid[]                                  $gasGrids
 * @var TariffGrid|null                               $editGrid
 * @var TariffGrid|null                               $latestElec
 * @var TariffGrid|null                               $latestGas
 * @var array<string,array{label:string,unit:string}> $elecLines
 * @var array<string,array{label:string,unit:string}> $gasLines
 * @var string                                        $et       Type d'énergie actif du formulaire
 * @var array<string,float>                           $elLines  Valeurs pré-remplies électricité
 * @var array<string,float>                           $glLines  Valeurs pré-remplies gaz
 * @var string                                        $today
 */
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->te('tariffs.title') ?> — <?= $this->te('app.title') ?></title>
<!-- Anti-FOUC : pose le thème (clair/sombre) avant le 1er rendu (localStorage > système). -->
<script>(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tariffs.css') ?>">
</head>
<body>
<div class="wrap">

<header>
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <div>
      <div class="logo-text">Manage Energy</div>
      <div class="logo-sub"><?= $this->te('tariffs.subtitle') ?></div>
    </div>
  </div>
  <div class="header-right">
    <a href="index.php" class="back"><?= $this->te('nav.back_dashboard') ?></a>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="<?= $this->e($this->t('common.theme')) ?>">🌙</button>
  </div>
</header>

<?php if ($success): ?>
<div class="alert alert-ok" style="margin-top:20px">✓ <?= $this->e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err" style="margin-top:20px">✗ <?= $this->e($error) ?></div>
<?php endif; ?>

<!-- ── Existing tariffs ─────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title"><?= $this->te('tariffs.elec') ?></span>
  <span class="section-line"></span>
</div>

<div class="grids-wrap">
<?php if (empty($elecGrids)): ?>
  <div class="empty"><?= $this->te('tariffs.none_elec') ?></div>
<?php else: foreach ($elecGrids as $g):
  $active = $g->isActiveOn(new \DateTimeImmutable('today'));
  $rowId  = 'lines-' . $g->id;
?>
  <div class="grid-row">
    <div>
      <div class="grid-name"><?= $this->e($g->name) ?></div>
      <div class="grid-dates">
        <?= $g->validFrom->format('d/m/Y') ?> →
        <?= $g->validTo ? $g->validTo->format('d/m/Y') : '∞' ?>
      </div>
    </div>
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? $this->te('common.active') : $this->te('common.inactive') ?></span>
    <?php if ($g->isShared()): ?><span class="grid-active yes" title="<?= $this->e($this->t('tariffs.shared_grid')) ?>"><?= $this->te('tariffs.shared') ?><?= $g->country ? ' · ' . $this->e($g->country) : '' ?></span><?php endif; ?>
    <button class="btn btn-ghost btn-sm" onclick="toggleLines('<?= $rowId ?>')"><?= $this->te('tariffs.detail') ?></button>
    <div class="grid-actions">
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm"><?= $this->te('tariffs.edit') ?></a>
      <form method="post" onsubmit="return confirm('<?= $this->e($this->t('tariffs.delete_confirm')) ?>')">
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="btn btn-red btn-sm"><?= $this->te('tariffs.delete') ?></button>
      </form>
    </div>
  </div>
  <div class="lines-detail" id="<?= $rowId ?>">
    <table class="lines-table">
      <?php foreach ($g->lines as $key => $amount): ?>
      <tr>
        <td><?= $this->e($elecLines[$key]['label'] ?? $key) ?></td>
        <td><?= number_format($amount, 7) ?> <?= $this->e($elecLines[$key]['unit'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; endif; ?>
</div>

<div class="section-header">
  <span class="section-title"><?= $this->te('tariffs.gas') ?></span>
  <span class="section-line"></span>
</div>

<div class="grids-wrap">
<?php if (empty($gasGrids)): ?>
  <div class="empty"><?= $this->te('tariffs.none_gas') ?></div>
<?php else: foreach ($gasGrids as $g):
  $active = $g->isActiveOn(new \DateTimeImmutable('today'));
  $rowId  = 'lines-gas-' . $g->id;
?>
  <div class="grid-row">
    <div>
      <div class="grid-name"><?= $this->e($g->name) ?></div>
      <div class="grid-dates">
        <?= $g->validFrom->format('d/m/Y') ?> →
        <?= $g->validTo ? $g->validTo->format('d/m/Y') : '∞' ?>
        <?php if ($g->pcsCoefficient ?? null): ?>
          <span style="margin-left:8px;color:var(--blue)">PCS <?= number_format($g->pcsCoefficient, 4) ?> kWh/m³</span>
        <?php endif; ?>
      </div>
    </div>
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? $this->te('common.active') : $this->te('common.inactive') ?></span>
    <?php if ($g->isShared()): ?><span class="grid-active yes" title="<?= $this->e($this->t('tariffs.shared_grid')) ?>"><?= $this->te('tariffs.shared') ?><?= $g->country ? ' · ' . $this->e($g->country) : '' ?></span><?php endif; ?>
    <button class="btn btn-ghost btn-sm" onclick="toggleLines('<?= $rowId ?>')"><?= $this->te('tariffs.detail') ?></button>
    <div class="grid-actions">
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm"><?= $this->te('tariffs.edit') ?></a>
      <form method="post" onsubmit="return confirm('<?= $this->e($this->t('tariffs.delete_confirm')) ?>')">
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="btn btn-red btn-sm"><?= $this->te('tariffs.delete') ?></button>
      </form>
    </div>
  </div>
  <div class="lines-detail" id="<?= $rowId ?>">
    <table class="lines-table">
      <?php foreach ($g->lines as $key => $amount): ?>
      <tr>
        <td><?= $this->e($gasLines[$key]['label'] ?? $key) ?></td>
        <td><?= number_format($amount, 7) ?> <?= $this->e($gasLines[$key]['unit'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; endif; ?>
</div>

<!-- ── Form ─────────────────────────────────────────────────────────────── -->
<div class="section-header" id="form">
  <span class="section-title"><?= $editGrid ? $this->te('tariffs.editing') : $this->te('tariffs.new') ?></span>
  <span class="section-line"></span>
  <?php if ($editGrid): ?>
  <a href="tariffs.php" class="back"><?= $this->te('common.cancel') ?></a>
  <?php endif; ?>
</div>

<div class="form-card">
  <div class="form-tabs">
    <button type="button" class="form-tab <?= $et === 'electricity' ? 'active' : '' ?>" onclick="switchTab('electricity', event)"><?= $this->te('tariffs.energy_electricity') ?></button>
    <button type="button" class="form-tab <?= $et === 'gas' ? 'active' : '' ?>" onclick="switchTab('gas', event)"><?= $this->te('tariffs.energy_gas') ?></button>
  </div>

  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="edit_id" value="<?= $this->e((string) ($editGrid?->id ?? '')) ?>">
    <input type="hidden" name="energy_type" id="energy_type_field" value="<?= $this->e($et) ?>">
    <?= \App\Security\Csrf::field() ?>

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.name') ?></label>
        <input type="text" name="name" class="form-input" required placeholder="ex. Engie bihoraire fév. 2026"
               value="<?= $this->e($editGrid?->name ?? '') ?>">
      </div>
      <div class="form-row" id="pcs-row" style="<?= $et === 'gas' ? '' : 'display:none' ?>">
        <label class="form-label"><?= $this->te('tariffs.pcs') ?> <span class="unit"><?= $this->te('tariffs.pcs_unit') ?></span></label>
        <input type="number" name="pcs_coefficient" step="0.0001" class="form-input" placeholder="10.5500"
               value="<?= $this->e($editGrid?->pcsCoefficient !== null ? number_format($editGrid->pcsCoefficient, 4, '.', '') : '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_from') ?></label>
        <input type="date" name="valid_from" class="form-input" required
               value="<?= $this->e($editGrid?->validFrom->format('Y-m-d') ?? $today) ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.valid_to') ?> <span class="unit"><?= $this->te('common.optional') ?></span></label>
        <input type="date" name="valid_to" class="form-input"
               value="<?= $this->e($editGrid?->validTo?->format('Y-m-d') ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.country') ?> <span class="unit">ISO-2, <?= $this->te('common.optional') ?></span></label>
        <input type="text" name="country" class="form-input" maxlength="2" placeholder="BE"
               value="<?= $this->e($editGrid?->country ?? '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label"><?= $this->te('tariffs.currency') ?> <span class="unit">ISO 4217</span></label>
        <input type="text" name="currency" class="form-input" maxlength="3" placeholder="EUR"
               value="<?= $this->e($editGrid?->currency ?? 'EUR') ?>">
      </div>
      <?php if ($isAdmin && !$editGrid): ?>
      <div class="form-row">
        <label class="form-label">
          <input type="checkbox" name="shared" value="1">
          <?= $this->te('tariffs.shared_grid') ?> <span class="unit"><?= $this->te('tariffs.shared_grid_hint') ?></span>
        </label>
      </div>
      <?php endif; ?>
    </div>

    <!-- Electricity lines -->
    <div id="elec-lines" style="<?= $et === 'electricity' ? '' : 'display:none' ?>">
      <div class="form-grid" style="margin-top:18px">
        <div class="lines-title"><?= $this->te('tariffs.lines_elec') ?></div>
        <?php if (!$editGrid && $latestElec): ?>
        <div class="prefill-hint">
          <?= $this->te('tariffs.prefill', ['name' => $latestElec->name, 'date' => $latestElec->validFrom->format('d/m/Y')]) ?>
        </div>
        <?php endif; ?>
        <?php foreach ($elecLines as $key => $def): ?>
        <div class="form-row">
          <label class="form-label"><?= $this->e($def['label']) ?> <span class="unit"><?= $def['unit'] ?></span></label>
          <input type="number" name="line_<?= $key ?>" step="0.0000001" class="form-input"
                 placeholder="0.0000000"
                 value="<?= $this->e(isset($elLines[$key]) ? number_format($elLines[$key], 7, '.', '') : '') ?>">
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Gas lines -->
    <div id="gas-lines" style="<?= $et === 'gas' ? '' : 'display:none' ?>">
      <div class="form-grid" style="margin-top:18px">
        <div class="lines-title"><?= $this->te('tariffs.lines_gas') ?></div>
        <?php if (!$editGrid && $latestGas): ?>
        <div class="prefill-hint">
          <?= $this->te('tariffs.prefill', ['name' => $latestGas->name, 'date' => $latestGas->validFrom->format('d/m/Y')]) ?>
        </div>
        <?php endif; ?>
        <?php foreach ($gasLines as $key => $def): ?>
        <div class="form-row">
          <label class="form-label"><?= $this->e($def['label']) ?> <span class="unit"><?= $def['unit'] ?></span></label>
          <input type="number" name="line_<?= $key ?>" step="0.0000001" class="form-input"
                 placeholder="0.0000000"
                 value="<?= $this->e(isset($glLines[$key]) ? number_format($glLines[$key], 7, '.', '') : '') ?>">
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-amber">
        <?= $editGrid ? $this->te('tariffs.update') : $this->te('tariffs.save') ?>
      </button>
      <?php if ($editGrid): ?>
      <a href="tariffs.php" class="btn btn-ghost"><?= $this->te('common.cancel') ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div style="margin-top:40px;padding-top:16px;border-top:1px solid var(--border);
            font-family:var(--mono);font-size:.76rem;color:var(--muted);display:flex;
            justify-content:space-between;flex-wrap:wrap;gap:8px;">
  <span>Manage Energy — <?= $this->te('tariffs.title') ?></span>
  <a href="../tools/migrate_db.php" style="color:var(--blue);text-decoration:none"><?= $this->te('tariffs.migrate_db') ?></a>
</div>

</div>

<script defer src="<?= \App\Support\Assets::url('assets/js/theme.js') ?>"></script>
<script src="<?= \App\Support\Assets::url('assets/js/tariffs.js') ?>"></script>
</body>
</html>