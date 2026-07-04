<?php
declare(strict_types=1);

use App\Domain\TariffLineCatalog;
use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\Infrastructure\Database;
use App\Repository\TariffRepository;
use App\Repository\UserRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

AuthGuard::protect($config);

$db          = new Database($config['database']);
$pdo         = $db->pdo();
$userId      = UserContext::currentWebUserId($pdo, $config);
$users       = new UserRepository($pdo);
$isAdmin     = ($users->findById($userId)?->isAdmin()) ?? false;

$profile     = $users->getProfile($userId);
$locale      = Locale::resolve($config, $profile['locale'] ?? null);
$choice      = Locale::explicitChoice($config);
if ($choice !== null && $choice !== ($profile['locale'] ?? null)) {
    $users->setLocale($userId, $choice);
}
$view        = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

$tariffRepo  = new TariffRepository($pdo, $userId, $isAdmin);
$error       = null;
$success     = null;

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (Csrf::validate($_POST['_csrf'] ?? null) === false) {
            throw new \RuntimeException($view->t('common.csrf_invalid'));
        }

        if ($action === 'save') {
            $editId     = filter_var($_POST['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $energyType = $_POST['energy_type'] ?? '';
            $name       = trim($_POST['name'] ?? '');
            $validFrom  = $_POST['valid_from'] ?? '';
            $validTo    = trim($_POST['valid_to'] ?? '') ?: null;

            if (!in_array($energyType, ['electricity', 'gas'], true)) throw new \InvalidArgumentException($view->t('tariffs.invalid_energy'));
            if ($name === '')     throw new \InvalidArgumentException($view->t('tariffs.name_required'));
            if ($validFrom === '') throw new \InvalidArgumentException($view->t('tariffs.from_required'));

            $lineKeys = TariffLineCatalog::keysFor($energyType);

            $lines = [];
            foreach ($lineKeys as $key) {
                $raw = $_POST['line_' . $key] ?? '';
                if ($raw === '') continue;
                $val = filter_var($raw, FILTER_VALIDATE_FLOAT);
                if ($val === false) throw new \InvalidArgumentException($view->t('tariffs.invalid_value', ['key' => $key]));
                $lines[$key] = $val;
            }

            $pcs = null;
            if ($energyType === 'gas' && ($_POST['pcs_coefficient'] ?? '') !== '') {
                $pcs = (float) $_POST['pcs_coefficient'];
            }

            $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
            $country = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

            $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'EUR')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new \InvalidArgumentException($view->t('account.invalid_currency'));
            }

            $shared = $isAdmin && ($_POST['shared'] ?? '') === '1';

            if ($editId !== null) {
                $tariffRepo->updateGrid(
                    $editId,
                    $energyType,
                    $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines,
                    $pcs,
                    $country,
                    $currency,
                );
                $success = $view->t('tariffs.saved', ['name' => $name]);
            } else {
                $tariffRepo->saveGrid(
                    $energyType,
                    $name,
                    new \DateTimeImmutable($validFrom),
                    $validTo ? new \DateTimeImmutable($validTo) : null,
                    $lines,
                    $pcs,
                    $country,
                    $currency,
                    $shared,
                );
                $success = $view->t('tariffs.saved', ['name' => $name]);
            }
        }

        if ($action === 'close') {
            $id      = (int) ($_POST['grid_id'] ?? 0);
            $validTo = $_POST['valid_to_close'] ?? '';
            if ($id <= 0)       throw new \InvalidArgumentException($view->t('tariffs.invalid_id'));
            if ($validTo === '') throw new \InvalidArgumentException($view->t('tariffs.end_required'));
            $tariffRepo->closeGrid($id, new \DateTimeImmutable($validTo));
            $success = $view->t('tariffs.closed');
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['grid_id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException($view->t('tariffs.invalid_id'));
            $tariffRepo->deleteGrid($id);
            $success = $view->t('tariffs.deleted');
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
        $error = $view->t('tariffs.invalid_id');
    } else {
        try {
            $editGrid = $tariffRepo->findById($editId);
            if ($editGrid === null) {
                $error = $view->t('tariffs.not_found');
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

echo $view->render('tariffs', [
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
    'isAdmin'    => $isAdmin,
    'available'  => Locale::available($config),
]);
