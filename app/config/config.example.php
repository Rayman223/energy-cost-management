<?php

declare(strict_types=1);

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'energy',
        'user' => 'energy_user',
        'password' => 'change_me',
        'charset' => 'utf8mb4',
    ],
    'webhook' => [
        'url' => 'https://hooks.energyid.eu/services/WebhookIn/xxx/yyy',
        'timeout' => 15,
    ],
    'timezone' => 'Europe/Brussels',
    'site' => [
        'remote_id' => 'maison-principale',
        'remote_name' => 'Maison principale',
    ],
];
