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
    'energyid' => [
        'provisioning_key' => 'change_me',
        'provisioning_secret' => 'change_me',
        'timeout' => 15,
        'device' => [
            'deviceId' => 'manage-energy-costs-1',
            'deviceName' => 'Manage Energy Costs Device',
            'firmwareVersion' => '1.0.0',
            'ipAddress' => '127.0.0.1',
            'macAddress' => '00:00:00:00:00:00',
            'localDeviceUrl' => 'http://localhost',
        ],
    ],
    'timezone' => 'Europe/Brussels',
];
