<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Meter;
use App\Repository\Exception\LimitReachedException;
use App\Support\Limits;
use PDO;

/**
 * Accès à `meters` : le parc de compteurs déclaré par l'utilisateur (#55).
 *
 * Scope tenant dur en constructeur, comme {@see BatteryRepository} : `user_id`
 * n'apparaît jamais dans une signature publique, il est injecté une fois et
 * appliqué à chaque requête — lecture comme écriture, y compris à l'UPDATE et au
 * DELETE, pour qu'un identifiant deviné ne touche pas la ligne d'autrui.
 *
 * Distinct de {@see \App\Infrastructure\MeterTopology}, qui RÉSOUT un compteur
 * (et le crée à la volée) pour les chemins d'ingestion : ici on administre le
 * parc, là on l'utilise.
 */
final class MeterRepository
{
    private const COLUMNS = 'id, energy_type, label, opened_on, closed_on';

    /**
     * @param int $maxPerEnergy Plafond anti-abus, cf. {@see Limits::metersPerEnergy()}.
     *                          Défaut égal au défaut de configuration : un appelant
     *                          qui oublierait de le passer obtient la garde
     *                          standard, jamais l'absence de garde.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly int $maxPerEnergy = Limits::DEFAULT_METERS_PER_ENERGY,
    ) {
    }

    /**
     * Parc complet, groupé par énergie puis du plus ancien au plus récent.
     *
     * L'ordre est stable et significatif : le premier compteur d'une énergie est
     * celui que résout {@see \App\Infrastructure\MeterTopology::findDefaultMeter()},
     * donc celui qui reçoit les relevés sans cible explicite. Le lire en tête de
     * liste, et non noyé dans un tri par libellé, évite d'avoir à le deviner.
     *
     * @return list<Meter>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM meters
              WHERE user_id = :uid
              ORDER BY FIELD(energy_type, \'electricity\', \'gas\', \'water\'), id'
        );
        $stmt->execute(['uid' => $this->userId]);

        return $this->hydrate($stmt->fetchAll());
    }

    /** @return list<Meter> */
    public function listByEnergy(string $energyType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM meters
              WHERE user_id = :uid AND energy_type = :etype
              ORDER BY id'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $energyType]);

        return $this->hydrate($stmt->fetchAll());
    }

    /** Un compteur du parc, ou null si l'identifiant est inconnu ou étranger. */
    public function find(int $id): ?Meter
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM meters WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);

        return $this->hydrate($stmt->fetchAll())[0] ?? null;
    }

    /**
     * Ce compteur existe-t-il ET appartient-il à l'utilisateur courant ?
     *
     * Distinct de `rowCount()` après écriture : sans `MYSQL_ATTR_FOUND_ROWS`, un
     * UPDATE qui ne change aucune valeur rapporte zéro ligne affectée bien que la
     * cible existe — s'y fier confondrait « compteur introuvable » et « renommé à
     * l'identique ».
     */
    public function owns(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM meters WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Nombre de compteurs déjà déclarés pour cette énergie. */
    public function countByEnergy(string $energyType): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM meters WHERE user_id = :uid AND energy_type = :etype'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $energyType]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Nombre de relevés portés par chaque compteur du parc, identifiant en clé.
     *
     * Les deux modèles sont additionnés : les index électriques passent par les
     * registres (`meter_readings` → `meter_registers`), le gaz et l'eau sont
     * rattachés en direct (`utility_readings.meter_id`). Un compteur sans relevé
     * est présent avec un zéro — l'absence de clé signifierait « compteur
     * inconnu », ce qui n'est pas la même chose.
     *
     * Côté électricité, on compte les HORODATAGES DISTINCTS, pas les lignes :
     * une saisie alimente jusqu'à cinq registres, donc jusqu'à cinq lignes de
     * `meter_readings`. Compter les lignes annoncerait « 1 000 relevés » à qui
     * en a saisi 200 — un chiffre faux, et affiché précisément là où il compte
     * le plus, dans la confirmation d'une suppression irréversible.
     *
     * Rendu pour tout le parc en une requête : la page affiche ce compte sur
     * chaque ligne, et une requête par compteur ferait un N+1 pour un chiffre
     * purement informatif.
     *
     * @return array<int, int> meter_id => nombre de relevés
     */
    public function readingCounts(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id,
                    (SELECT COUNT(DISTINCT mr.reading_at) FROM meter_readings mr
                       JOIN meter_registers reg ON reg.id = mr.register_id
                      WHERE reg.meter_id = m.id)
                  + (SELECT COUNT(*) FROM utility_readings ur WHERE ur.meter_id = m.id) AS total
               FROM meters m
              WHERE m.user_id = :uid'
        );
        $stmt->execute(['uid' => $this->userId]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $counts[(int) $row['id']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Insère un compteur et renvoie son identifiant. `$meter->id` est IGNORÉ :
     * l'identifiant est attribué par la base.
     *
     * La garde de plafond est ici, et pas seulement dans la route : c'est le seul
     * point commun à tous les chemins de création présents et à venir. Elle porte
     * sur l'énergie du compteur, pas sur le parc entier (cf.
     * {@see Limits::metersPerEnergy()}).
     *
     * Course possible entre deux requêtes simultanées du même compte, comme pour
     * {@see ApiTokenRepository::create()} : le dépassement serait alors d'une
     * unité, sans conséquence — la limite protège d'un abus soutenu, pas d'un
     * double-clic.
     *
     * @throws LimitReachedException si le plafond de cette énergie est atteint
     */
    public function insert(Meter $meter): int
    {
        if ($this->countByEnergy($meter->energyType) >= $this->maxPerEnergy) {
            throw LimitReachedException::meters($meter->energyType, $this->maxPerEnergy);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO meters (user_id, energy_type, label, opened_on, closed_on)
             VALUES (:uid, :etype, :label, :opened, :closed)'
        );
        $stmt->execute([
            'uid'    => $this->userId,
            'etype'  => $meter->energyType,
            'label'  => $meter->label,
            'opened' => $meter->openedOn?->format('Y-m-d'),
            'closed' => $meter->closedOn?->format('Y-m-d'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Met à jour le compteur `$id` ; `$meter->id` est ignoré, seul `$id` cible.
     *
     * `energy_type` n'est PAS réécrite : elle décide de quelle table viennent les
     * relevés du compteur, la changer rendrait sa série incohérente en silence
     * (cf. {@see Meter}). La route ne l'offre pas à l'édition, et cet UPDATE
     * l'ignorerait même si elle le faisait.
     */
    public function update(int $id, Meter $meter): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE meters SET label = :label, opened_on = :opened, closed_on = :closed
              WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'id'     => $id,
            'uid'    => $this->userId,
            'label'  => $meter->label,
            'opened' => $meter->openedOn?->format('Y-m-d'),
            'closed' => $meter->closedOn?->format('Y-m-d'),
        ]);
    }

    /**
     * Supprime le compteur et, par cascade FK, TOUS ses relevés — registres et
     * index électriques, relevés gaz/eau. C'est irréversible et annoncé comme tel
     * dans la confirmation : des index orphelins ne se rattacheraient à aucun
     * compteur et ne seraient plus valorisables.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM meters WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<Meter>
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = Meter::fromRow([
                'id'          => (int) $row['id'],
                'energy_type' => (string) $row['energy_type'],
                'label'       => (string) $row['label'],
                'closed_on'   => $row['closed_on'] !== null ? (string) $row['closed_on'] : null,
                'opened_on'   => $row['opened_on'] !== null ? (string) $row['opened_on'] : null,
            ]);
        }

        return $out;
    }
}
