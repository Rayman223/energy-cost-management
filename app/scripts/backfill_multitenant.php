<?php

declare(strict_types=1);

/**
 * Migration one-shot des données historiques (Data_*) vers le modèle multi-tenant,
 * rattachées au compte propriétaire. À lancer une fois après avoir appliqué la
 * migration 2026-07-01 (tables créées) et t'être connecté via OIDC (pour que ton
 * compte existe et reçoive les données).
 *
 * Usage :
 *   php app/scripts/backfill_multitenant.php                # 1er utilisateur (toi)
 *   php app/scripts/backfill_multitenant.php --user=42      # utilisateur ciblé
 */

use App\Infrastructure\Database;
use App\Infrastructure\MultiTenantBackfill;
use App\Repository\UserRepository;

$config = require __DIR__ . '/../bootstrap.php';

$ownerArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user=')) {
        $ownerArg = (int) substr($arg, 7);
    }
}

$database = new Database($config['database']);
$pdo = $database->pdo();
$userRepository = new UserRepository($pdo);

$backfill = new MultiTenantBackfill($pdo, $userRepository);
$counts = $backfill->run($ownerArg);

$ownerId = $counts['owner_user_id'];
unset($counts['owner_user_id']);

$owner = $userRepository->findById($ownerId);
$label = $owner !== null ? ($owner->displayName . ' (' . $owner->oidcSub . ')') : ('#' . $ownerId);

echo 'Backfill vers l\'utilisateur #' . $ownerId . ' — ' . $label . "\n";
foreach ($counts as $stream => $rows) {
    echo '  ' . $stream . ' : ' . $rows . " ligne(s) copiée(s)\n";
}

exit(0);
