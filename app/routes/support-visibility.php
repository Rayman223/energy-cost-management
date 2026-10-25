<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\Infrastructure\Database;
use App\Repository\SupportRepository;
use App\Security\AuthGuard;
use App\Security\Csrf;
use App\Security\UserContext;
use App\Support\SupportConfig;
use App\Support\Url;

$config = require __DIR__ . '/../bootstrap.php';
SecurityHeaders::send($config);
if (!SupportConfig::enabled($config) || !AuthGuard::isOidcEnabled($config) || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); return; }
AuthGuard::protect($config);
if (Csrf::validate($_POST[Csrf::FIELD] ?? null) === false) { http_response_code(403); echo 'Invalid CSRF token'; return; }
$pdo = (new Database($config['database']))->pdo();
$repo = new SupportRepository($pdo);
$mode = SupportConfig::stripeMode($config);
$userId = UserContext::currentWebUserId($pdo, $config);
$users = new App\Repository\UserRepository($pdo);
$me = $users->findById($userId);
// Ne pas utiliser le cache de statut d'AuthGuard pour une modification de compte.
if ($me === null || !$me->isActive()) { http_response_code(403); return; }
if (isset($_POST['admin_user_id'])) {
    $target = filter_var($_POST['admin_user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$me->isAdmin()) { http_response_code(403); return; }
    if ($target === false) { http_response_code(422); return; }
    if ($users->findById($target) === null) { http_response_code(404); return; }
    $repo->adminSetBadge($target, ($_POST['badge_enabled'] ?? '') === '1', $mode);
    header('Location: ' . Url::to('support'), true, 303);
    return;
}
$repo->setVisibility($userId, ($_POST['public_opt_in'] ?? '') === '1', $mode);
header('Location: ' . Url::to('support'), true, 303);
