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
                    'connection_fee_kwh', 'public_service_annual',
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
    'energy'                => ['label' => 'Énergie fournisseur',               'unit' => '€/kWh'],
    'subscription'          => ['label' => 'Abonnement fournisseur',            'unit' => '€/mois'],
    'energy_contribution'   => ['label' => 'Contribution énergie',              'unit' => '€/kWh'],
    'federal_excise'        => ['label' => 'Accise fédérale',                   'unit' => '€/kWh'],
    'distribution'          => ['label' => 'Distribution (variable)',           'unit' => '€/kWh'],
    'distribution_fixed'    => ['label' => 'Distribution (fixe)',               'unit' => '€/an'],
    'transport'             => ['label' => 'Transport',                         'unit' => '€/kWh'],
    'meter_reading_annual'  => ['label' => 'Relevé de compteur',                'unit' => '€/an'],
    'connection_fee_kwh'    => ['label' => 'Redevance de raccordement',         'unit' => '€/kWh'],
    'public_service_annual' => ['label' => 'Obligations de service public',     'unit' => '€/an'],
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
        <input type="number" name="pcs_coefficient" step="0.0001" class="form-input" placeholder="10.5500"
               value="<?= htmlspecialchars($editGrid?->pcsCoefficient !== null ? number_format($editGrid->pcsCoefficient, 4, '.', '') : '') ?>">
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

<script src="<?= \App\Support\Assets::url('assets/js/tariffs.js') ?>"></script>
</body>
</html>