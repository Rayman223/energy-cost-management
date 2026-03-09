<?php
declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\TariffRepository;
use App\Security\WebAccessGuard;

$config = require __DIR__ . '/../bootstrap.php';

WebAccessGuard::protect($config['web_security'] ?? []);

$db          = new Database($config['database']);
$tariffRepo  = new TariffRepository($db->pdo());
$error       = null;
$success     = null;

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $editId     = filter_var($_POST['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $energyType = $_POST['energy_type'] ?? '';
            $name       = trim($_POST['name'] ?? '');
            $validFrom  = $_POST['valid_from'] ?? '';
            $validTo    = trim($_POST['valid_to'] ?? '') ?: null;

            if (!in_array($energyType, ['electricity', 'gas'], true)) throw new \InvalidArgumentException('Type énergie invalide.');
            if ($name === '')     throw new \InvalidArgumentException('Le nom est requis.');
            if ($validFrom === '') throw new \InvalidArgumentException('La date de début est requise.');

            $lineKeys = $energyType === 'electricity'
                ? [
                    'energy_simple', 'energy_t1', 'energy_t2',
                    'subscription',
                    'distribution_t1', 'distribution_t2',
                    'transport',
                    'management_annual', 'prosumer_annual',
                    'excise_duty', 'energy_contribution', 'green_contribution',
                    'public_service_annual',
                    'injection_t1', 'injection_t2',
                  ]
                : [
                    'energy', 'subscription',
                    'energy_contribution', 'federal_excise',
                    'distribution', 'distribution_fixed',
                    'transport', 'meter_reading_annual',
                  ];

            $lines = [];
            foreach ($lineKeys as $key) {
                $raw = $_POST['line_' . $key] ?? '';
                if ($raw === '') continue;
                $val = filter_var($raw, FILTER_VALIDATE_FLOAT);
                if ($val === false) throw new \InvalidArgumentException("Valeur invalide pour $key.");
                $lines[$key] = $val;
            }

            $pcs = null;
            if ($energyType === 'gas' && ($_POST['pcs_coefficient'] ?? '') !== '') {
                $pcs = (float) $_POST['pcs_coefficient'];
            }

            if ($editId !== null) {
                $tariffRepo->updateGrid(
                    $editId,
                    $energyType,
                    $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines,
                    $pcs,
                );
                $success = "Tarif « $name » enregistré.";
            } else {
                $tariffRepo->saveGrid(
                    $energyType,
                    $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines,
                    $pcs,
                );
                $success = "Tarif « $name » enregistré.";
            }
        }

        if ($action === 'close') {
            $id      = (int) ($_POST['grid_id'] ?? 0);
            $validTo = $_POST['valid_to_close'] ?? '';
            if ($id <= 0)       throw new \InvalidArgumentException('ID invalide.');
            if ($validTo === '') throw new \InvalidArgumentException('Date de fin requise.');
            $tariffRepo->closeGrid($id, new \DateTimeImmutable($validTo));
            $success = 'Tarif clôturé.';
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['grid_id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException('ID invalide.');
            $tariffRepo->deleteGrid($id);
            $success = 'Tarif supprimé.';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── Load grids ─────────────────────────────────────────────────────────────
$elecGrids = $tariffRepo->findAll('electricity');
$gasGrids  = $tariffRepo->findAll('gas');

// Latest grids for pre-fill (sorted DESC by valid_from)
$latestElec = !empty($elecGrids) ? $elecGrids[0] : null;
$latestGas  = !empty($gasGrids)  ? $gasGrids[0]  : null;

// Pre-fill form if editing
$editGrid = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($editId === false) {
        $error = 'ID invalide.';
    } else {
        try {
            $editGrid = $tariffRepo->findById($editId);
            if ($editGrid === null) {
                $error = 'Tarif introuvable.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// ── Line definitions ───────────────────────────────────────────────────────
$elecLines = [
    'energy_simple'         => ['label' => 'Énergie simple (monohoraire)',     'unit' => '€/kWh'],
    'energy_t1'             => ['label' => 'Énergie T1 (jour)',                'unit' => '€/kWh'],
    'energy_t2'             => ['label' => 'Énergie T2 (nuit)',                'unit' => '€/kWh'],
    'subscription'          => ['label' => 'Abonnement fournisseur',           'unit' => '€/mois'],
    'distribution_t1'       => ['label' => 'Distribution T1 (jour)',           'unit' => '€/kWh'],
    'distribution_t2'       => ['label' => 'Distribution T2 (nuit)',           'unit' => '€/kWh'],
    'transport'             => ['label' => 'Transport',                        'unit' => '€/kWh'],
    'management_annual'     => ['label' => 'Gestion (fixe annuel)',            'unit' => '€/an'],
    'prosumer_annual'       => ['label' => 'Taxe prosumer BRUGEL',             'unit' => '€/an'],
    'excise_duty'           => ['label' => "Droit d'accise spécial",           'unit' => '€/kWh'],
    'energy_contribution'   => ['label' => 'Contribution énergie',             'unit' => '€/kWh'],
    'green_contribution'    => ['label' => 'Contribution verte & cogénération','unit' => '€/kWh'],
    'public_service_annual' => ['label' => 'Obligations de service public',    'unit' => '€/an'],
    'injection_t1'          => ['label' => 'Crédit injection T1',              'unit' => '€/kWh'],
    'injection_t2'          => ['label' => 'Crédit injection T2',              'unit' => '€/kWh'],
];

$gasLines = [
    'energy'               => ['label' => 'Énergie fournisseur',              'unit' => '€/kWh'],
    'subscription'         => ['label' => 'Abonnement fournisseur',            'unit' => '€/mois'],
    'energy_contribution'  => ['label' => 'Contribution énergie',              'unit' => '€/kWh'],
    'federal_excise'       => ['label' => 'Accise fédérale',                   'unit' => '€/kWh'],
    'distribution'         => ['label' => 'Distribution (variable)',           'unit' => '€/kWh'],
    'distribution_fixed'   => ['label' => 'Distribution (fixe)',               'unit' => 'c€/kWh'],
    'transport'            => ['label' => 'Transport',                         'unit' => '€/kWh'],
    'meter_reading_annual' => ['label' => 'Relevé de compteur',                'unit' => '€/an'],
];

// Lines to display: edit mode uses the grid's own lines; new mode pre-fills from latest
$et      = $editGrid?->energyType ?? 'electricity';
$elLines = ($editGrid && $editGrid->energyType === 'electricity') ? $editGrid->lines : ($latestElec?->lines ?? []);
$glLines = ($editGrid && $editGrid->energyType === 'gas')         ? $editGrid->lines : ($latestGas?->lines  ?? []);

$today = date('Y-m-d');
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
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0b0d10; --surface: #111318; --surface2: #181c23;
    --border: #1f2530; --border2: #2a3040;
    --text: #c8d0dc; --muted: #556070;
    --amber: #f5a623; --amber-dim: rgba(245,166,35,.12);
    --green: #2fd58e; --green-dim: rgba(47,213,142,.10);
    --blue: #41b3f5; --red: #f56565; --red-dim: rgba(245,101,101,.10);
    --mono: 'Space Mono', monospace; --sans: 'Syne', sans-serif;
  }
  body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh;
         background-image: radial-gradient(circle, #1e2530 1px, transparent 1px); background-size: 28px 28px; }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 0 24px 60px; }

  header { display:flex; align-items:center; justify-content:space-between; padding:22px 0 18px; border-bottom:1px solid var(--border); }
  .logo { display:flex; align-items:center; gap:14px; }
  .logo-icon { width:36px; height:36px; background:var(--amber); border-radius:8px; display:grid; place-items:center; font-size:18px; box-shadow:0 0 16px rgba(245,166,35,.3); }
  .logo-text { font-size:1.05rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#eef2f8; }
  .logo-sub  { font-family:var(--mono); font-size:.65rem; color:var(--muted); }
  .back { color:var(--blue); text-decoration:none; font-size:.82rem; font-family:var(--mono); }
  .back:hover { text-decoration:underline; }

  .section-header { display:flex; align-items:baseline; gap:12px; margin:32px 0 16px; }
  .section-title  { font-size:.62rem; font-weight:700; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); }
  .section-line   { flex:1; height:1px; background:var(--border); }

  .alert { padding:12px 16px; border-radius:8px; font-size:.85rem; margin-bottom:20px; font-family:var(--mono); }
  .alert-ok  { background:var(--green-dim); border:1px solid rgba(47,213,142,.3); color:var(--green); }
  .alert-err { background:var(--red-dim);   border:1px solid rgba(245,101,101,.3); color:var(--red); }

  .form-card { background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:24px; }
  .form-tabs { display:flex; gap:4px; margin-bottom:22px; }
  .form-tab  { padding:7px 18px; border-radius:6px; font-size:.78rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
               cursor:pointer; border:1px solid var(--border2); color:var(--muted); background:transparent; transition:all .15s; }
  .form-tab.active { background:var(--amber); color:#0b0d10; border-color:var(--amber); }
  .form-tab:hover:not(.active) { color:var(--text); }

  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .form-row { display:flex; flex-direction:column; gap:6px; }
  .form-row.full { grid-column:1/-1; }
  .form-label { font-size:.62rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); }
  .form-label .unit { font-weight:400; letter-spacing:0; text-transform:none; margin-left:6px; color:var(--muted); opacity:.7; }
  .form-input, .form-select {
    background:var(--bg); border:1px solid var(--border2); border-radius:6px;
    padding:9px 12px; color:var(--text); font-family:var(--mono); font-size:.85rem;
    outline:none; transition:border-color .2s; width:100%;
  }
  .form-input:focus, .form-select:focus { border-color:var(--amber); }
  .form-select option { background:var(--surface); }

  .lines-title { grid-column:1/-1; font-size:.62rem; font-weight:700; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); margin-top:4px; }
  .prefill-hint { grid-column:1/-1; font-size:.68rem; font-family:var(--mono); color:var(--blue);
                  background:rgba(65,179,245,.07); border:1px solid rgba(65,179,245,.2);
                  border-radius:6px; padding:8px 12px; }

  .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 20px; border-radius:6px;
         font-family:var(--sans); font-size:.8rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
         cursor:pointer; border:none; transition:opacity .15s; }
  .btn-amber { background:var(--amber); color:#0b0d10; }
  .btn-ghost { background:transparent; color:var(--muted); border:1px solid var(--border2); }
  .btn-ghost:hover { color:var(--text); }
  .btn-red   { background:var(--red-dim); color:var(--red); border:1px solid rgba(245,101,101,.3); }
  .btn:active { opacity:.8; }
  .form-actions { display:flex; gap:10px; align-items:center; margin-top:20px; }

  .grids-wrap { background:var(--surface); border:1px solid var(--border); border-radius:10px; overflow:hidden; margin-bottom:12px; }
  .grid-row { display:grid; grid-template-columns:1fr 120px 120px auto; align-items:center;
              padding:14px 20px; border-top:1px solid var(--border); gap:16px; }
  .grid-row:first-child { border:none; }
  .grid-row:hover { background:var(--surface2); }
  .grid-name  { font-weight:700; font-size:.9rem; color:#eef2f8; }
  .grid-dates { font-family:var(--mono); font-size:.75rem; color:var(--muted); margin-top:3px; }
  .grid-active { display:inline-block; padding:2px 8px; border-radius:4px; font-size:.68rem; font-weight:700; }
  .grid-active.yes { background:var(--green-dim); color:var(--green); }
  .grid-active.no  { background:var(--border); color:var(--muted); }
  .grid-actions { display:flex; gap:6px; }
  .btn-sm { padding:5px 12px; font-size:.7rem; }

  .lines-detail { padding:0 20px 16px; display:none; }
  .lines-detail.open { display:block; }
  .lines-table { width:100%; border-collapse:collapse; font-size:.78rem; }
  .lines-table td { padding:5px 12px; font-family:var(--mono); color:var(--muted); border-top:1px solid var(--border); }
  .lines-table td:first-child { color:var(--text); }
  .lines-table td:last-child  { color:var(--amber); text-align:right; }

  .empty { padding:24px; color:var(--muted); font-size:.85rem; font-style:italic; }

  @media(max-width:680px) {
    .form-grid { grid-template-columns:1fr; }
    .grid-row { grid-template-columns:1fr auto; }
  }
</style>
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
<div class="alert alert-ok" style="margin-top:20px">✓ <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-err" style="margin-top:20px">✗ <?= htmlspecialchars($error) ?></div>
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
      <div class="grid-name"><?= htmlspecialchars($g->name) ?></div>
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
        <td><?= htmlspecialchars($elecLines[$key]['label'] ?? $key) ?></td>
        <td><?= number_format($amount, 7) ?> <?= htmlspecialchars($elecLines[$key]['unit'] ?? '') ?></td>
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
      <div class="grid-name"><?= htmlspecialchars($g->name) ?></div>
      <div class="grid-dates">
        <?= $g->validFrom->format('d/m/Y') ?> →
        <?= $g->validTo ? $g->validTo->format('d/m/Y') : '∞' ?>
        <?php if ($g->pcsCoefficient ?? null): ?>
          <span style="margin-left:8px;color:var(--blue)">PCS <?= $g->pcsCoefficient ?> kWh/m³</span>
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
        <td><?= htmlspecialchars($gasLines[$key]['label'] ?? $key) ?></td>
        <td><?= number_format($amount, 7) ?> <?= htmlspecialchars($gasLines[$key]['unit'] ?? '') ?></td>
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
    <input type="hidden" name="edit_id" value="<?= htmlspecialchars((string) ($editGrid?->id ?? '')) ?>">
    <input type="hidden" name="energy_type" id="energy_type_field" value="<?= htmlspecialchars($et) ?>">

    <div class="form-grid">
      <div class="form-row">
        <label class="form-label">Nom du tarif</label>
        <input type="text" name="name" class="form-input" required placeholder="ex. Engie bihoraire fév. 2026"
               value="<?= htmlspecialchars($editGrid?->name ?? '') ?>">
      </div>
      <div class="form-row" id="pcs-row" style="<?= $et === 'gas' ? '' : 'display:none' ?>">
        <label class="form-label">Coefficient PCS <span class="unit">kWh/m³</span></label>
        <input type="number" name="pcs_coefficient" step="0.01" class="form-input" placeholder="10.55"
               value="<?= htmlspecialchars((string)($editGrid?->pcsCoefficient ?? '')) ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Valable à partir du</label>
        <input type="date" name="valid_from" class="form-input" required
               value="<?= htmlspecialchars($editGrid?->validFrom->format('Y-m-d') ?? $today) ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Valable jusqu'au <span class="unit">optionnel</span></label>
        <input type="date" name="valid_to" class="form-input"
               value="<?= htmlspecialchars($editGrid?->validTo?->format('Y-m-d') ?? '') ?>">
      </div>
    </div>

    <!-- Electricity lines -->
    <div id="elec-lines" style="<?= $et === 'electricity' ? '' : 'display:none' ?>">
      <div class="form-grid" style="margin-top:18px">
        <div class="lines-title">Composantes tarifaires — Électricité</div>
        <?php if (!$editGrid && $latestElec): ?>
        <div class="prefill-hint">
          ↺ Pré-rempli depuis « <?= htmlspecialchars($latestElec->name) ?> » (<?= $latestElec->validFrom->format('d/m/Y') ?>). Modifiez uniquement les valeurs qui ont changé.
        </div>
        <?php endif; ?>
        <?php foreach ($elecLines as $key => $def): ?>
        <div class="form-row">
          <label class="form-label"><?= htmlspecialchars($def['label']) ?> <span class="unit"><?= $def['unit'] ?></span></label>
          <input type="number" name="line_<?= $key ?>" step="0.0000001" class="form-input"
                 placeholder="0.0000000"
                 value="<?= htmlspecialchars(isset($elLines[$key]) ? number_format($elLines[$key], 7, '.', '') : '') ?>">
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
          ↺ Pré-rempli depuis « <?= htmlspecialchars($latestGas->name) ?> » (<?= $latestGas->validFrom->format('d/m/Y') ?>). Modifiez uniquement les valeurs qui ont changé.
        </div>
        <?php endif; ?>
        <?php foreach ($gasLines as $key => $def): ?>
        <div class="form-row">
          <label class="form-label"><?= htmlspecialchars($def['label']) ?> <span class="unit"><?= $def['unit'] ?></span></label>
          <input type="number" name="line_<?= $key ?>" step="0.0000001" class="form-input"
                 placeholder="0.0000000"
                 value="<?= htmlspecialchars(isset($glLines[$key]) ? number_format($glLines[$key], 7, '.', '') : '') ?>">
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

<script>
function syncTariffInputs(type) {
  const isElectricity = type === 'electricity';

  document.querySelectorAll('#elec-lines input').forEach((input) => {
    input.disabled = !isElectricity;
  });

  document.querySelectorAll('#gas-lines input').forEach((input) => {
    input.disabled = isElectricity;
  });

  document.querySelectorAll('#pcs-row input').forEach((input) => {
    input.disabled = isElectricity;
  });
}

function switchTab(type, event) {
  document.getElementById('energy_type_field').value = type;
  document.querySelectorAll('.form-tab').forEach(t => t.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('elec-lines').style.display = type === 'electricity' ? '' : 'none';
  document.getElementById('gas-lines').style.display  = type === 'gas'         ? '' : 'none';
  document.getElementById('pcs-row').style.display    = type === 'gas'         ? '' : 'none';
  syncTariffInputs(type);
}

function toggleLines(id) {
  document.getElementById(id).classList.toggle('open');
}

syncTariffInputs(document.getElementById('energy_type_field').value);
</script>
</body>
</html>