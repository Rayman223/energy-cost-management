<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Meter;
use InvalidArgumentException;
use PDO;

/**
 * Topologie des compteurs : résolution (et création à la volée) du compteur
 * d'un utilisateur pour une énergie donnée, et de ses registres.
 * Source de vérité unique partagée par la lecture (dashboard), l'ingestion
 * (cron) et la migration (backfill).
 *
 * Depuis #55, `meters` porte les TROIS énergies et un utilisateur peut en
 * posséder plusieurs par énergie. Les méthodes de cette classe résolvent le
 * compteur PAR DÉFAUT — le plus ancien — et ne servent que de repli quand
 * l'appelant n'a pas désigné de compteur précis.
 */
final class MeterTopology
{
    /** Registres électricité connus (jusqu'à 5 index par compteur). */
    public const ELECTRICITY_REGISTERS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

    /**
     * Énergies portées par la table `meters`. Alias de {@see Meter::ENERGIES},
     * qui est la source unique depuis #55 — la liste avait déjà trois copies
     * dans le dépôt côté registres, ce n'était pas la peine d'en créer une
     * quatrième côté énergies.
     *
     * @var list<string>
     */
    public const ENERGIES = Meter::ENERGIES;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Compteur de l'utilisateur pour cette énergie, créé s'il n'existe pas.
     *
     * Le libellé est laissé VIDE : il est dérivé à l'affichage, dans la langue
     * du lecteur. Écrire ici un libellé français le servirait tel quel à un
     * utilisateur néerlandophone, et le figerait en base.
     */
    public function ensureMeter(int $userId, string $energyType = 'electricity'): int
    {
        $existing = $this->findDefaultMeter($userId, $energyType);
        if ($existing !== null) {
            return $existing;
        }

        $this->pdo->prepare(
            "INSERT INTO meters (user_id, energy_type, label) VALUES (:uid, :etype, '')"
        )->execute(['uid' => $userId, 'etype' => $this->assertEnergy($energyType)]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Compteur PAR DÉFAUT de l'utilisateur pour cette énergie — le plus ancien —,
     * null s'il n'en possède aucun.
     */
    public function findDefaultMeter(int $userId, string $energyType = 'electricity'): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM meters WHERE user_id = :uid AND energy_type = :etype ORDER BY id LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'etype' => $this->assertEnergy($energyType)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Compteur électricité de l'utilisateur, créé s'il n'existe pas. */
    public function ensureElectricityMeter(int $userId): int
    {
        return $this->ensureMeter($userId, 'electricity');
    }

    /** Compteur électricité de l'utilisateur, null s'il n'existe pas. */
    public function findElectricityMeter(int $userId): ?int
    {
        return $this->findDefaultMeter($userId, 'electricity');
    }

    /**
     * Garde-fou : une énergie inconnue viendrait d'un appelant fautif, pas d'une
     * saisie utilisateur. Échouer ici évite d'insérer une ligne que l'ENUM
     * tronquerait silencieusement en mode non strict.
     */
    private function assertEnergy(string $energyType): string
    {
        if (!in_array($energyType, self::ENERGIES, true)) {
            throw new InvalidArgumentException('Unknown energy type: ' . $energyType);
        }

        return $energyType;
    }

    /**
     * Crée les registres manquants et retourne la carte complète.
     *
     * @param list<string> $keys
     * @return array<string, int> register_key => id
     */
    public function ensureRegisters(int $meterId, array $keys = self::ELECTRICITY_REGISTERS): array
    {
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO meter_registers (meter_id, register_key, unit) VALUES (:mid, :key, 'kWh')"
        );
        foreach ($keys as $key) {
            $insert->execute(['mid' => $meterId, 'key' => $key]);
        }

        return $this->registerMap($meterId);
    }

    /** @return array<string, int> register_key => id (vide si compteur inconnu) */
    public function registerMap(int $meterId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, register_key FROM meter_registers WHERE meter_id = :mid');
        $stmt->execute(['mid' => $meterId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $map[(string) $row['register_key']] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * Carte des registres du compteur PAR DÉFAUT de l'utilisateur, sans création
     * (lecture seule).
     *
     * Ne voit qu'un compteur : réservée aux chemins qui en désignent un seul —
     * saisie, historique, index du jour. Les lectures de RAPPORT passent par
     * {@see registerIdsForUser()}, qui couvre tout le parc.
     *
     * @return array<string, int> register_key => id (vide si aucun compteur)
     */
    public function registerMapForUser(int $userId): array
    {
        $meterId = $this->findElectricityMeter($userId);

        return $meterId === null ? [] : $this->registerMap($meterId);
    }

    /**
     * Ce compteur existe-t-il, appartient-il à l'utilisateur et porte-t-il cette
     * énergie ? Garde d'écriture : distingue « compteur étranger » (à refuser) de
     * « compteur à moi, pas encore de registres » (à équiper), que
     * {@see ownedRegisterMap()} rendrait tous deux comme une carte vide.
     */
    public function ownsMeter(int $userId, int $meterId, string $energyType = 'electricity'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM meters WHERE id = :mid AND user_id = :uid AND energy_type = :etype LIMIT 1'
        );
        $stmt->execute(['mid' => $meterId, 'uid' => $userId, 'etype' => $this->assertEnergy($energyType)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Carte des registres d'un compteur DÉSIGNÉ, à condition qu'il appartienne à
     * l'utilisateur et porte la bonne énergie (#55).
     *
     * La vérification d'appartenance est faite ICI, dans la requête, et non
     * laissée à l'appelant : cette carte est ce qui décide dans quelles lignes on
     * écrit. Un identifiant forgé rend une carte VIDE — donc aucune écriture
     * possible — plutôt qu'une carte pointant sur le compteur d'autrui.
     *
     * @return array<string, int> register_key => id (vide si le compteur est
     *         inconnu, étranger, ou d'une autre énergie)
     */
    public function ownedRegisterMap(int $userId, int $meterId, string $energyType = 'electricity'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT reg.id, reg.register_key
               FROM meter_registers reg
               JOIN meters m ON m.id = reg.meter_id
              WHERE reg.meter_id = :mid AND m.user_id = :uid AND m.energy_type = :etype'
        );
        $stmt->execute(['mid' => $meterId, 'uid' => $userId, 'etype' => $this->assertEnergy($energyType)]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $map[(string) $row['register_key']] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * Date de fermeture d'un compteur (#55), ou `null` s'il est ouvert — ou
     * inconnu, ce qui revient au même pour l'appelant : rien à opposer.
     *
     * Rendue brute ('Y-m-d') : c'est {@see Meter::closureInstantFor()} qui la
     * situe dans le fuseau du lecteur, une seule fois, plutôt que chaque
     * repository à sa façon.
     */
    public function closedOn(int $meterId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT closed_on FROM meters WHERE id = :mid LIMIT 1');
        $stmt->execute(['mid' => $meterId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    /**
     * Registres de TOUS les compteurs électriques de l'utilisateur, groupés par
     * clé de registre (#55).
     *
     * C'est la vue « flotte » : un foyer peut relever une maison et un atelier,
     * et un rapport doit additionner les deux. Une clé absente du retour signifie
     * qu'aucun compteur ne porte ce registre — distinct d'une liste vide, qui ne
     * peut pas se produire ici.
     *
     * Une seule requête pour tout le parc : la jointure `meters → meter_registers`
     * est indexée par `idx_meters_user_energy` puis par la clé unique
     * `uq_meter_registers`. L'ordre par identifiant de compteur est stable et
     * significatif — le plus ancien d'abord, c'est-à-dire le compteur par défaut.
     *
     * @return array<string, list<int>> register_key => ids, compteur le plus ancien en tête
     */
    public function registerIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT reg.id, reg.register_key
               FROM meter_registers reg
               JOIN meters m ON m.id = reg.meter_id
              WHERE m.user_id = :uid AND m.energy_type = 'electricity'
              ORDER BY reg.meter_id, reg.id"
        );
        $stmt->execute(['uid' => $userId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $map[(string) $row['register_key']][] = (int) $row['id'];
            }
        }

        return $map;
    }
}
