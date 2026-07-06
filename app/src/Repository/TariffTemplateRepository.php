<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use RuntimeException;

/**
 * Templates de tarifs créés par l'utilisateur (structure de champs réutilisable,
 * sans montants). Scopés au propriétaire : jamais de fuite cross-tenant.
 *
 * Les templates « fournis » (belges/génériques) ne passent PAS par ce dépôt :
 * ils vivent en PHP (TariffTemplateCatalog).
 */
final class TariffTemplateRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    /**
     * Templates de l'utilisateur pour un type d'énergie, champs chargés en une
     * seule requête (évite le N+1).
     *
     * @return list<array{id:int, energy_type:string, country:?string, name:string, fields:list<array{key:string, kind:string, label:?string, sort:int}>}>
     */
    public function findForEnergy(string $energyType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, country, name
             FROM tariff_templates
             WHERE user_id = :uid AND energy_type = :type
             ORDER BY name ASC'
        );
        $stmt->execute(['uid' => $this->userId, 'type' => $energyType]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        $ids       = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $fieldsMap = $this->fetchFieldsForIds($ids);

        return array_map(
            fn (array $r): array => [
                'id'          => (int) $r['id'],
                'energy_type' => (string) $r['energy_type'],
                'country'     => $r['country'] !== null ? (string) $r['country'] : null,
                'name'        => (string) $r['name'],
                'fields'      => $fieldsMap[(int) $r['id']] ?? [],
            ],
            $rows,
        );
    }

    /**
     * @return array{id:int, energy_type:string, country:?string, name:string, fields:list<array{key:string, kind:string, label:?string, sort:int}>}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, country, name
             FROM tariff_templates
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $fieldsMap = $this->fetchFieldsForIds([(int) $row['id']]);

        return [
            'id'          => (int) $row['id'],
            'energy_type' => (string) $row['energy_type'],
            'country'     => $row['country'] !== null ? (string) $row['country'] : null,
            'name'        => (string) $row['name'],
            'fields'      => $fieldsMap[(int) $row['id']] ?? [],
        ];
    }

    /**
     * Crée un template et ses champs. Renvoie l'identifiant créé.
     *
     * @param list<array{key:string, kind:string, label:?string}> $fields
     */
    public function save(string $energyType, ?string $country, string $name, array $fields): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tariff_templates (user_id, energy_type, country, name)
                 VALUES (:uid, :type, :country, :name)'
            );
            $stmt->execute([
                'uid'     => $this->userId,
                'type'    => $energyType,
                'country' => $country,
                'name'    => $name,
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $fieldStmt = $this->pdo->prepare(
                'INSERT INTO tariff_template_fields (template_id, line_key, component_kind, label, sort_order)
                 VALUES (:tid, :key, :kind, :label, :sort)'
            );
            $sort = 0;
            foreach ($fields as $field) {
                $fieldStmt->execute([
                    'tid'   => $id,
                    'key'   => $field['key'],
                    'kind'  => $field['kind'],
                    'label' => $field['label'] ?? null,
                    'sort'  => $sort++,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tariff_templates WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Template introuvable.'); // pas de fuite cross-tenant
        }
    }

    /**
     * @param  list<int> $ids
     * @return array<int, list<array{key:string, kind:string, label:?string, sort:int}>>
     */
    private function fetchFieldsForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT template_id, line_key, component_kind, label, sort_order
             FROM tariff_template_fields
             WHERE template_id IN ($placeholders)
             ORDER BY sort_order, id"
        );
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['template_id']][] = [
                'key'   => (string) $row['line_key'],
                'kind'  => (string) $row['component_kind'],
                'label' => $row['label'] !== null ? (string) $row['label'] : null,
                'sort'  => (int) $row['sort_order'],
            ];
        }

        return $map;
    }
}
