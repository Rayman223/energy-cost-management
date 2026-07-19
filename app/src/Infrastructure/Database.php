<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

final class Database
{
    private PDO $pdo;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Fuseau de session forcé à UTC, rejoué à chaque (re)connexion. Rend
            // NOW()/CURRENT_TIMESTAMP/DEFAULT CURRENT_TIMESTAMP indépendants du
            // fuseau du serveur MariaDB (qui peut être CEST/SYSTEM). +00:00 est
            // DST-proof : toute la chaîne applicative stocke et lit en UTC.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
