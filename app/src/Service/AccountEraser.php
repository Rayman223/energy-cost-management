<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Effacement RGPD (droit à l'effacement) : supprime le compte et TOUTES ses
 * données, en transaction. La plupart des tables cascadent via FK ; celles sans
 * FK vers users (tariff_grids personnelles, webhook_sync_state) sont supprimées
 * explicitement avant le compte.
 *
 * Exception « templates » : les templates de tarifs rendus PUBLICS sont
 * CONSERVÉS (ils servent aux autres comptes) mais ANONYMISÉS (user_id → NULL,
 * plus aucun lien vers le compte supprimé) ; seuls les templates PRIVÉS sont
 * supprimés. Les usages (compteur) de l'utilisateur cascadent via FK sur
 * users ; ceux d'autres comptes sur un template public conservé survivent.
 */
final class AccountEraser
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function erase(int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            // Sans FK cascade vers users → suppression explicite.
            $this->delete('DELETE FROM tariff_grids WHERE user_id = :uid', $userId);
            $this->delete('DELETE FROM webhook_sync_state WHERE user_id = :uid', $userId);

            // Templates : les publics sont conservés mais anonymisés (user_id → NULL,
            // plus de lien vers le compte) ; les privés sont supprimés (leurs
            // tariff_template_fields cascadent via FK).
            $this->delete("UPDATE tariff_templates SET user_id = NULL WHERE user_id = :uid AND visibility = 'public'", $userId);
            $this->delete("DELETE FROM tariff_templates WHERE user_id = :uid AND visibility = 'private'", $userId);

            // Cascade : user_profiles, meters→registers→readings, utility_readings,
            // api_tokens, user_integrations, tariff_template_usages
            // (les usages de CET utilisateur ; ceux d'autres comptes sur un template public restent).
            $this->delete('DELETE FROM users WHERE id = :uid', $userId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function delete(string $sql, int $userId): void
    {
        $this->pdo->prepare($sql)->execute(['uid' => $userId]);
    }
}
