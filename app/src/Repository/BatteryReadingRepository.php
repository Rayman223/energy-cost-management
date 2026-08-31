<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ReadingGranularity;
use App\Repository\Contract\BatteryIngestionInterface;
use App\Repository\Contract\BatteryReadingsInterface;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Accès à `battery_readings` : les index cumulés d'entrée et de sortie d'UNE
 * batterie (#26).
 *
 * Double scope dur en constructeur — l'utilisateur ET la batterie. Le second ne
 * suffit pas : un identifiant de batterie deviné désignerait la batterie d'un
 * autre compte. Chaque écriture vérifie donc d'abord que la batterie appartient
 * bien à l'utilisateur ({@see self::assertOwnedBattery()}), et chaque lecture
 * joint `batteries` sur `user_id`.
 *
 * Les deux compteurs vivent sur une même ligne mais se relèvent indépendamment :
 * un onduleur qui n'expose que la décharge laisse `charge_index_kwh` à NULL, et
 * une seconde écriture au même horodatage viendra la compléter. Tous les calculs
 * de delta sautent donc les NULL colonne par colonne.
 */
final class BatteryReadingRepository implements BatteryIngestionInterface, BatteryReadingsInterface
{
    /** Clé publique => colonne. Source unique des deux correspondances. */
    private const COLUMNS = [
        'charge'    => 'charge_index_kwh',
        'discharge' => 'discharge_index_kwh',
    ];

    /** Mémoïsation du contrôle d'appartenance : une seule requête par instance. */
    private ?bool $owned = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly int $batteryId,
    ) {
    }

    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByKind, bool $replace = false): int
    {
        $indexByKind = $this->onlyKnownKinds($indexByKind);
        if ($indexByKind === [] || !$this->assertOwnedBattery()) {
            return 0;
        }

        $at = Dates::toDbString($timestamp);

        // Lecture préalable : elle ne décide PAS de l'écriture (l'INSERT ci-dessous
        // est idempotent par lui-même), elle sert à compter ce qui sera réellement
        // écrit. L'import a besoin de ce compte pour distinguer « importé » de
        // « doublon » ; sans lui, `rowCount()` d'un ON DUPLICATE KEY UPDATE ne dit
        // pas combien de COLONNES ont changé.
        $existing = $this->rowAt($at);
        $written  = 0;
        foreach ($indexByKind as $kind => $value) {
            if ($existing === null || $existing[$kind] === null || $replace) {
                $written++;
            }
        }

        // COALESCE et non affectation directe : en mode normal la valeur déjà
        // présente gagne (on complète un trou, on n'écrase jamais en silence) ; en
        // mode écrasement c'est la valeur fournie qui gagne, mais un compteur
        // ABSENT de la soumission arrive à NULL et COALESCE le laisse intact —
        // corriger la charge ne doit pas effacer la décharge.
        $onDuplicate = $replace
            ? 'charge_index_kwh    = COALESCE(VALUES(charge_index_kwh), charge_index_kwh),
               discharge_index_kwh = COALESCE(VALUES(discharge_index_kwh), discharge_index_kwh)'
            : 'charge_index_kwh    = COALESCE(charge_index_kwh, VALUES(charge_index_kwh)),
               discharge_index_kwh = COALESCE(discharge_index_kwh, VALUES(discharge_index_kwh))';

        $stmt = $this->pdo->prepare(
            'INSERT INTO battery_readings (battery_id, reading_at, charge_index_kwh, discharge_index_kwh)
             VALUES (:bid, :at, :charge, :discharge)
             ON DUPLICATE KEY UPDATE ' . $onDuplicate
        );
        $stmt->execute([
            'bid'       => $this->batteryId,
            'at'        => $at,
            'charge'    => $indexByKind['charge']    ?? null,
            'discharge' => $indexByKind['discharge'] ?? null,
        ]);

        return $written;
    }

    /**
     * @param list<string> $kinds
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $kinds): array
    {
        $at = Dates::toDbString($timestamp);

        $bounds = [];
        foreach ($kinds as $kind) {
            $column = self::COLUMNS[$kind] ?? null;
            if ($column === null) {
                $bounds[$kind] = ['min' => null, 'max' => null, 'exists' => false];
                continue;
            }

            // `IS NOT NULL` dans chaque sous-requête : un relevé où SEULE l'autre
            // colonne est renseignée n'est pas une borne pour celle-ci. Sans ce
            // filtre, le voisin le plus proche serait « NULL », et la validation de
            // croissance laisserait passer n'importe quelle valeur.
            $stmt = $this->pdo->prepare(
                "SELECT
                    (SELECT {$column} FROM battery_readings
                       WHERE battery_id = :bid_b AND reading_at < :at_b AND {$column} IS NOT NULL
                       ORDER BY reading_at DESC LIMIT 1) AS min_v,
                    (SELECT {$column} FROM battery_readings
                       WHERE battery_id = :bid_a AND reading_at > :at_a AND {$column} IS NOT NULL
                       ORDER BY reading_at ASC LIMIT 1) AS max_v,
                    EXISTS(SELECT 1 FROM battery_readings
                       WHERE battery_id = :bid_e AND reading_at = :at_e AND {$column} IS NOT NULL) AS exists_v"
            );
            // Placeholders dupliqués : PDO en mode natif interdit la réutilisation
            // d'un nom dans une même requête.
            $stmt->execute([
                'bid_b' => $this->batteryId, 'at_b' => $at,
                'bid_a' => $this->batteryId, 'at_a' => $at,
                'bid_e' => $this->batteryId, 'at_e' => $at,
            ]);
            /** @var array{min_v: string|null, max_v: string|null, exists_v: int|string} $row */
            $row = $stmt->fetch();

            $bounds[$kind] = [
                'min'    => $row['min_v'] === null ? null : (float) $row['min_v'],
                'max'    => $row['max_v'] === null ? null : (float) $row['max_v'],
                'exists' => (bool) $row['exists_v'],
            ];
        }

        return $bounds;
    }

    public function readingPresentInDay(DateTimeImmutable $timestamp, string $timezone): bool
    {
        // Bornes du jour civil LOCAL, ramenées en UTC (colonnes UTC, session
        // MariaDB forcée à +00:00 — pas de CONVERT_TZ). Le calcul du créneau et la
        // gestion des transitions d'heure vivent dans l'enum, partagée avec les
        // index électriques.
        [$start, $end] = ReadingGranularity::Day->bucket($timestamp, new DateTimeZone($timezone));

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM battery_readings
              WHERE battery_id = :bid AND reading_at >= :start AND reading_at < :end AND reading_at <> :at
              LIMIT 1'
        );
        $stmt->execute([
            'bid'   => $this->batteryId,
            'start' => Dates::toDbString($start),
            'end'   => Dates::toDbString($end),
            'at'    => Dates::toDbString($timestamp),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{charge: list<array{ts:int,value:float}>, discharge: list<array{ts:int,value:float}>}
     */
    public function indexSeries(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT br.reading_at, br.charge_index_kwh, br.discharge_index_kwh
             FROM battery_readings br
             INNER JOIN batteries b ON b.id = br.battery_id
             WHERE br.battery_id = :bid AND b.user_id = :uid
             ORDER BY br.reading_at ASC'
        );
        $stmt->execute(['bid' => $this->batteryId, 'uid' => $this->userId]);

        $series = ['charge' => [], 'discharge' => []];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ts = Dates::fromDbString((string) $row['reading_at'])->getTimestamp();
            foreach (self::COLUMNS as $kind => $column) {
                // NULL omis de SA série : un point à zéro y creuserait une
                // consommation négative, suivie d'un rattrapage fantôme.
                if ($row[$column] !== null) {
                    $series[$kind][] = ['ts' => $ts, 'value' => (float) $row[$column]];
                }
            }
        }

        return $series;
    }

    /** Nombre de relevés de la batterie (pagination de l'historique). */
    public function countReadings(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM battery_readings br
             INNER JOIN batteries b ON b.id = br.battery_id
             WHERE br.battery_id = :bid AND b.user_id = :uid'
        );
        $stmt->execute(['bid' => $this->batteryId, 'uid' => $this->userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Une page d'historique, du plus récent au plus ancien, chaque relevé portant
     * ses deltas de charge et de décharge.
     *
     * Une ligne de plus que demandé est lue : c'est le relevé immédiatement plus
     * ancien que la page, nécessaire au delta de la dernière ligne affichée. Elle
     * est ensuite écartée — même mécanique que
     * {@see UtilityReadingRepository::getReadingsPage()}.
     *
     * @return list<array{id:int,reading_at:string,charge:float|null,discharge:float|null,delta_charge:float|null,delta_discharge:float|null}>
     */
    public function getReadingsPage(int $perPage, int $offset): array
    {
        $perPage = max(1, $perPage);
        $offset  = max(0, $offset);
        // Interpolés (entiers castés) plutôt que liés : PDO en mode émulation
        // désactivée passerait LIMIT/OFFSET en chaînes, que MySQL rejette.
        $limit = $perPage + 1;

        $stmt = $this->pdo->prepare(
            'SELECT br.id, br.reading_at, br.charge_index_kwh, br.discharge_index_kwh
             FROM battery_readings br
             INNER JOIN batteries b ON b.id = br.battery_id
             WHERE br.battery_id = :bid AND b.user_id = :uid
             ORDER BY br.reading_at DESC, br.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute(['bid' => $this->batteryId, 'uid' => $this->userId]);
        $rowsDesc = $stmt->fetchAll();

        $withDeltas = $this->withDeltas(array_reverse($rowsDesc));
        if (count($rowsDesc) > $perPage) {
            array_shift($withDeltas);
        }

        return array_reverse($withDeltas);
    }

    /** Supprime un relevé de CETTE batterie. Renvoie false si l'id est étranger. */
    public function deleteReading(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE br FROM battery_readings br
             INNER JOIN batteries b ON b.id = br.battery_id
             WHERE br.id = :id AND br.battery_id = :bid AND b.user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'bid' => $this->batteryId, 'uid' => $this->userId]);

        return $stmt->rowCount() > 0;
    }

    /** Vide l'historique de la batterie (repartir propre après un import fautif). */
    public function deleteAll(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE br FROM battery_readings br
             INNER JOIN batteries b ON b.id = br.battery_id
             WHERE br.battery_id = :bid AND b.user_id = :uid'
        );
        $stmt->execute(['bid' => $this->batteryId, 'uid' => $this->userId]);

        return $stmt->rowCount();
    }

    /**
     * La batterie visée appartient-elle bien à l'utilisateur scopé ?
     *
     * Défense en profondeur : les appelants valident déjà l'identifiant, mais
     * l'INSERT ne peut pas porter de jointure — sans ce contrôle, un identifiant
     * deviné écrirait dans la batterie d'autrui. Mémoïsé : le scope est immuable
     * pour la durée de vie de l'instance.
     */
    private function assertOwnedBattery(): bool
    {
        if ($this->owned === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM batteries WHERE id = :bid AND user_id = :uid LIMIT 1');
            $stmt->execute(['bid' => $this->batteryId, 'uid' => $this->userId]);
            $this->owned = $stmt->fetchColumn() !== false;
        }

        return $this->owned;
    }

    /**
     * Valeurs déjà en base à cet horodatage exact, ou null si aucune ligne.
     *
     * @return array{charge: float|null, discharge: float|null}|null
     */
    private function rowAt(string $at): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT charge_index_kwh, discharge_index_kwh FROM battery_readings
              WHERE battery_id = :bid AND reading_at = :at LIMIT 1'
        );
        $stmt->execute(['bid' => $this->batteryId, 'at' => $at]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'charge'    => $row['charge_index_kwh'] === null ? null : (float) $row['charge_index_kwh'],
            'discharge' => $row['discharge_index_kwh'] === null ? null : (float) $row['discharge_index_kwh'],
        ];
    }

    /**
     * Écarte les clés inconnues plutôt que d'échouer : les appelants valident déjà
     * leurs entrées, et une clé en trop ne doit pas faire tomber tout un batch.
     *
     * @param array<string, float> $indexByKind
     * @return array<string, float>
     */
    private function onlyKnownKinds(array $indexByKind): array
    {
        return array_intersect_key($indexByKind, self::COLUMNS);
    }

    /**
     * Ajoute à chaque relevé ses deltas. Attend un ordre CROISSANT ; renvoie le
     * même ordre.
     *
     * Le delta d'un compteur se prend contre le dernier relevé où CE compteur
     * était renseigné, pas contre la ligne précédente : une ligne où seule la
     * charge figure ne doit pas interrompre la série de décharge, ni produire un
     * delta aberrant à la reprise.
     *
     * @param  array<int, mixed> $rowsAsc
     * @return list<array{id:int,reading_at:string,charge:float|null,discharge:float|null,delta_charge:float|null,delta_discharge:float|null}>
     */
    private function withDeltas(array $rowsAsc): array
    {
        $result = [];
        /** @var array<string, float|null> $previous */
        $previous = ['charge' => null, 'discharge' => null];

        foreach ($rowsAsc as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = [
                'charge'    => $row['charge_index_kwh'] === null ? null : (float) $row['charge_index_kwh'],
                'discharge' => $row['discharge_index_kwh'] === null ? null : (float) $row['discharge_index_kwh'],
            ];

            $deltas = [];
            foreach ($values as $kind => $value) {
                $prev = $previous[$kind];
                $deltas[$kind] = ($value !== null && $prev !== null) ? round($value - $prev, 3) : null;
                if ($value !== null) {
                    $previous[$kind] = $value;
                }
            }

            $result[] = [
                'id'              => (int) $row['id'],
                'reading_at'      => (string) $row['reading_at'],
                'charge'          => $values['charge'],
                'discharge'       => $values['discharge'],
                'delta_charge'    => $deltas['charge'],
                'delta_discharge' => $deltas['discharge'],
            ];
        }

        return $result;
    }
}
