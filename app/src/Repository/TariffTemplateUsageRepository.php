<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Compteur de popularité des templates de tarifs : une ligne par
 * (template, utilisateur). Une utilisation = un utilisateur distinct, quel que
 * soit le nombre de grilles créées depuis le template.
 *
 * `template_ref` est polymorphe : 'builtin:<code>' (templates fournis, en PHP)
 * ou 'user:<id>' (templates perso, en base).
 */
final class TariffTemplateUsageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Enregistre l'utilisation d'un template par un utilisateur. Idempotent :
     * un même couple (template, utilisateur) n'est compté qu'une fois.
     */
    public function record(string $templateRef, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tariff_template_usages (template_ref, user_id)
             VALUES (:ref, :uid)
             ON DUPLICATE KEY UPDATE first_used_at = first_used_at'
        );
        $stmt->execute(['ref' => $templateRef, 'uid' => $userId]);
    }

    /**
     * Nombre d'utilisateurs distincts par référence de template.
     *
     * @return array<string, int> template_ref => nb d'utilisateurs
     */
    public function countsByRef(): array
    {
        $stmt = $this->pdo->query(
            'SELECT template_ref, COUNT(*) AS n
             FROM tariff_template_usages
             GROUP BY template_ref'
        );

        if ($stmt === false) {
            return [];
        }

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['template_ref']] = (int) $row['n'];
        }

        return $counts;
    }
}
