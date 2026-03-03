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
    'meters' => [
        'timeout' => 10,
        'dries_url' => 'http://192.168.1.5/api/v1/data',
        'solar_url' => 'http://192.168.1.168/api/v1/data',
        // Les chemins sont testés dans l'ordre: le 1er trouvé est utilisé.
        'paths' => [
            'prelev_jour' => ['electricity_import_t1_kwh', 'electricity.import.t1', '1.8.1'],
            'prelev_nuit' => ['electricity_import_t2_kwh', 'electricity.import.t2', '1.8.2'],
            'injec_jour' => ['electricity_export_t1_kwh', 'electricity.export.t1', '2.8.1'],
            'injec_nuit' => ['electricity_export_t2_kwh', 'electricity.export.t2', '2.8.2'],
            // Valeur production côté compteur (souvent en Wh), stockée en Wh dans Data_Solaire.
            'solar_production_wh' => ['solar_production_wh', 'solar.production.wh', 'production'],
        ],
    ],
    'timezone' => 'Europe/Brussels',
];
