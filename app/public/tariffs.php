<?php
declare(strict_types=1);

use App\Domain\TariffLineCatalog;
use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Repository\TariffRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\View\View;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

AuthGuard::protect($config);

$db          = new Database($config['database']);
$tariffRepo  = new TariffRepository($db->pdo());
$error       = null;
$success     = null;

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException('Requête invalide (jeton CSRF manquant ou expiré). Veuillez réessayer.');
        }

        if ($action === 'save') {
            $editId     = filter_var($_POST['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $energyType = $_POST['energy_type'] ?? '';
            $name       = trim($_POST['name'] ?? '');
            $validFrom  = $_POST['valid_from'] ?? '';
            $validTo    = trim($_POST['valid_to'] ?? '') ?: null;

            if (!in_array($energyType, ['electricity', 'gas'], true)) throw new \InvalidArgumentException('Type énergie invalide.');
            if ($name === '')     throw new \InvalidArgumentException('Le nom est requis.');
            if ($validFrom === '') throw new \InvalidArgumentException('La date de début est requise.');

            $lineKeys = TariffLineCatalog::keysFor($energyType);

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

// ── Line definitions (source unique : TariffLineCatalog) ─────────────────────
$elecLines = TariffLineCatalog::electricity();
$gasLines  = TariffLineCatalog::gas();

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
