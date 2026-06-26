<?php
declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\TariffRepository;
use App\Security\WebAccessGuard;
use App\View\View;

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

echo (new View(__DIR__ . '/../templates'))->render('tariffs', [
    'error'      => $error,
    'success'    => $success,
    'elecGrids'  => $elecGrids,
    'gasGrids'   => $gasGrids,
    'editGrid'   => $editGrid,
    'latestElec' => $latestElec,
    'latestGas'  => $latestGas,
    'elecLines'  => $elecLines,
    'gasLines'   => $gasLines,
    'et'         => $et,
    'elLines'    => $elLines,
    'glLines'    => $glLines,
    'today'      => $today,
]);
