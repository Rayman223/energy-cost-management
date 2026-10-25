<?php

declare(strict_types=1);

use App\Infrastructure\Database;
use App\Repository\SupportRepository;
use App\Service\StripeSupportWebhook;
use App\Support\SupportConfig;

$config = require __DIR__ . '/../bootstrap.php';
if (!SupportConfig::enabled($config) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(404); return; }
$body = file_get_contents('php://input', false, null, 0, 262145);
if (!is_string($body) || strlen($body) > 262144) { http_response_code(413); return; }
$db = new Database($config['database']);
$service = new StripeSupportWebhook(new SupportRepository($db->pdo()), is_array($config['support'] ?? null) ? $config['support'] : []);
$accepted = $service->handle($body, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
http_response_code($accepted ? 200 : 400);
header('Content-Type: application/json; charset=utf-8');
echo $accepted ? '{"received":true}' : '{"error":"invalid_event"}';
