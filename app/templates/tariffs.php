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
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tarifs — Manage Energy</title>
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
      <div class="logo-sub">Gestion des tarifs</div>
    </div>
  </div>
  <a href="index.php" class="back">← Dashboard</a>
</header>

<?php if ($success): ?>
<div class="alert alert-ok" style="margin-top:20px">✓ <?= $this->e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err" style="margin-top:20px">✗ <?= $this->e($error) ?></div>
<?php endif; ?>

<!-- ── Existing tariffs ─────────────────────────────────────────────────── -->
<div class="section-header">
  <span class="section-title">Tarifs électricité</span>
  <span class="section-line"></span>
</div>

<div class="grids-wrap">
<?php if (empty($elecGrids)): ?>
  <div class="empty">Aucun tarif électricité configuré.</div>
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
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? 'Actif' : 'Inactif' ?></span>
    <button class="btn btn-ghost btn-sm" onclick="toggleLines('<?= $rowId ?>')">Détail</button>
    <div class="grid-actions">
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm">Modifier</a>
      <form method="post" onsubmit="return confirm('Supprimer ce tarif ?')">
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <button type="submit" class="btn btn-red btn-sm">Suppr.</button>
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
  <span class="section-title">Tarifs gaz</span>
  <span class="section-line"></span>
</div>

<div class="grids-wrap">
<?php if (empty($gasGrids)): ?>
  <div class="empty">Aucun tarif gaz configuré.</div>
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
    <span class="grid-active <?= $active ? 'yes' : 'no' ?>"><?= $active ? 'Actif' : 'Inactif' ?></span>
    <button class="btn btn-ghost btn-sm" onclick="toggleLines('<?= $rowId ?>')">Détail</button>
    <div class="grid-actions">
      <a href="?edit=<?= $g->id ?>#form" class="btn btn-ghost btn-sm">Modifier</a>
      <form method="post" onsubmit="return confirm('Supprimer ce tarif ?')">
        <input type="hidden" name="action"  value="delete">
        <input type="hidden" name="grid_id" value="<?= $g->id ?>">
        <button type="submit" class="btn btn-red btn-sm">Suppr.</button>
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
  <span class="section-title"><?= $editGrid ? 'Modifier le tarif' : 'Nouveau tarif' ?></span>
  <span class="section-line"></span>
  <?php if ($editGrid): ?>
  <a href="tariffs.php" class="back">Annuler</a>
  <?php endif; ?>
</div>

<div class="form-card">
  <div class="form-tabs">
    <button type="button" class="form-tab <?= $et === 'electricity' ? 'active' : '' ?>" onclick="switchTab('electricity', event)">Électricité</button>
    <button type="button" class="form-tab <?= $et === 'gas' ? 'active' : '' ?>" onclick="switchTab('gas', event)">Gaz</button>
  </div>

  <form method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="edit_id" value="<?= $this->e((string) ($editGrid?->id ?? '')) ?>">
    <input type="hidden" name="energy_type" id="energy_type_field" value="<?= $this->e($et) ?>">

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label">Nom du tarif</label>
        <input type="text" name="name" class="form-input" required placeholder="ex. Engie bihoraire fév. 2026"
               value="<?= $this->e($editGrid?->name ?? '') ?>">
      </div>
      <div class="form-row" id="pcs-row" style="<?= $et === 'gas' ? '' : 'display:none' ?>">
        <label class="form-label">Coefficient PCS <span class="unit">kWh/m³</span></label>
        <input type="number" name="pcs_coefficient" step="0.0001" class="form-input" placeholder="10.5500"
               value="<?= $this->e($editGrid?->pcsCoefficient !== null ? number_format($editGrid->pcsCoefficient, 4, '.', '') : '') ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Valable à partir du</label>
        <input type="date" name="valid_from" class="form-input" required
               value="<?= $this->e($editGrid?->validFrom->format('Y-m-d') ?? $today) ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Valable jusqu'au <span class="unit">optionnel</span></label>
        <input type="date" name="valid_to" class="form-input"
               value="<?= $this->e($editGrid?->validTo?->format('Y-m-d') ?? '') ?>">
      </div>
    </div>

    <!-- Electricity lines -->
    <div id="elec-lines" style="<?= $et === 'electricity' ? '' : 'display:none' ?>">
      <div class="form-grid" style="margin-top:18px">
        <div class="lines-title">Composantes tarifaires — Électricité</div>
        <?php if (!$editGrid && $latestElec): ?>
        <div class="prefill-hint">
          ↺ Pré-rempli depuis « <?= $this->e($latestElec->name) ?> » (<?= $latestElec->validFrom->format('d/m/Y') ?>). Modifiez uniquement les valeurs qui ont changé.
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
        <div class="lines-title">Composantes tarifaires — Gaz</div>
        <?php if (!$editGrid && $latestGas): ?>
        <div class="prefill-hint">
          ↺ Pré-rempli depuis « <?= $this->e($latestGas->name) ?> » (<?= $latestGas->validFrom->format('d/m/Y') ?>). Modifiez uniquement les valeurs qui ont changé.
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
        <?= $editGrid ? 'Mettre à jour' : 'Enregistrer le tarif' ?>
      </button>
      <?php if ($editGrid): ?>
      <a href="tariffs.php" class="btn btn-ghost">Annuler</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div style="margin-top:40px;padding-top:16px;border-top:1px solid var(--border);
            font-family:var(--mono);font-size:.68rem;color:var(--muted);display:flex;
            justify-content:space-between;flex-wrap:wrap;gap:8px;">
  <span>Manage Energy v2 — Tarifs</span>
  <a href="../tools/migrate_db.php" style="color:var(--blue);text-decoration:none">→ Migration DB</a>
</div>

</div>

<script src="<?= \App\Support\Assets::url('assets/js/tariffs.js') ?>"></script>
</body>
</html>