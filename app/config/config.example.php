<?php

declare(strict_types=1);

return [
    'database' => [
        'host'    => '127.0.0.1',
        'port'    => 3307,
        'name'    => 'energy',
        'user'    => 'energy_user',
        'password'=> 'change_me',
        'charset' => 'utf8mb4',
    ],

    'energyid' => [
        'provisioning_key'    => 'change_me',
        'provisioning_secret' => 'change_me',
        'timeout'             => 15,
        'device'              => [
            'deviceId'       => 'manage-energy-costs-1',
            'deviceName'     => 'Manage Energy Costs Device',
            'firmwareVersion'=> '2.0.0',
            'ipAddress'      => '127.0.0.1',
            'macAddress'     => '00:00:00:00:00:00',
            'localDeviceUrl' => 'http://localhost',
        ],
    ],

    'meters' => [
        'timeout'   => 10,
        'dries_url' => 'http://192.168.1.5/api/v1/data',
        'solar_url' => 'http://192.168.1.7/api/v1/data',
        // Paths tested in order: first numeric value found is used.
        'paths' => [
            'prelev_jour'         => ['total_power_import_t1_kwh'],
            'prelev_nuit'         => ['total_power_import_t2_kwh'],
            'injec_jour'          => ['total_power_export_t1_kwh'],
            'injec_nuit'          => ['total_power_export_t2_kwh'],
            // Solar dongle: same structure as P1 meter, cumulative kWh export index.
            'solar_production_wh' => ['total_power_export_kwh'],
        ],
    ],

    'gas' => [
        // m³ → kWh PCS conversion factor.
        // Sibelga reference value for Brussels natural gas: 10.55 kWh/m³.
        // Update per period if your supplier provides a variable coefficient.
        'pcs_coefficient' => 10.55,
    ],

    'timezone' => 'Europe/Brussels',
];