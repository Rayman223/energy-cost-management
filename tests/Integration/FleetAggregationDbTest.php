<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MeterTopology;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * Agrégation multi-compteur des rapports (#55). S'auto-skippe sans base de test.
 *
 * Le pivot de tous ces tests est un INVARIANT, pas une valeur attendue écrite à
 * la main : **deux compteurs dont les index se somment doivent rendre exactement
 * ce que rendrait un compteur unique portant la somme de leurs index**. Il tient
 * parce que l'interpolation est linéaire — si les deux compteurs sont relevés aux
 * mêmes instants, interp(A) + interp(B) = interp(A+B) — et il se vérifie sans
 * recopier la moindre arithmétique du code testé, ce qu'une valeur en dur ferait.
 *
 * Deux comptes sont donc montés en parallèle : l'un avec deux compteurs, l'autre
 * avec un seul portant la somme. Toute méthode de flotte doit les rendre égaux.
 *
 * Les cas qui SORTENT de l'invariant sont testés à part, parce qu'ils décrivent
 * précisément ce qu'un compteur unique ne peut pas exprimer : compteur mis en
 * service en cours de période, compteur muet, cadences hétérogènes.
 */
final class FleetAggregationDbTest extends DatabaseTestCase
{
    /** Compte à deux compteurs. */
    private int $fleetUserId = 0;

    /** Compte à un seul compteur, portant la somme des index du premier. */
    private int $singleUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $users              = new UserRepository($this->pdo());
        $this->fleetUserId  = $users->create('https://iss.test', 'fleet', 'test', 'Fleet')->id;
        $this->singleUserId = $users->create('https://iss.test', 'single', 'test', 'Single')->id;
    }

    protected function clean(): void
    {
        foreach ([
            'meter_readings', 'meter_registers', 'utility_readings', 'meters',
            'user_profiles', 'users',
        ] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    // ── Montage ──────────────────────────────────────────────────────────────

    private function newMeter(int $userId, string $energyType = 'electricity', ?string $closedOn = null): int
    {
        $this->pdo()
            ->prepare('INSERT INTO meters (user_id, energy_type, label, closed_on) VALUES (:uid, :etype, :label, :closed)')
            ->execute([
                'uid'    => $userId,
                'etype'  => $energyType,
                'label'  => 'M' . $userId,
                'closed' => $closedOn,
            ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Écrit un jeu d'index sur un compteur DÉSIGNÉ.
     *
     * En SQL direct et non via ElectricityReadingRepository : celui-ci ne vise
     * que le compteur par défaut (la saisie ciblée est la phase suivante), il ne
     * permettrait donc pas de monter un parc.
     *
     * @param array<string, float> $indexByRegister
     */
    private function write(int $meterId, string $at, array $indexByRegister): void
    {
        $registers = (new MeterTopology($this->pdo()))->ensureRegisters($meterId);
        $stmt      = $this->pdo()->prepare(
            'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:r, :a, :v)'
        );
        foreach ($indexByRegister as $key => $value) {
            $stmt->execute(['r' => $registers[$key], 'a' => $at, 'v' => $value]);
        }
    }

    private function elec(int $userId): ElectricityReadingRepository
    {
        return new ElectricityReadingRepository($this->pdo(), $userId);
    }

    /**
     * Les QUATRE registres import/export d'une trame, alimentés.
     *
     * Les quatre, et pas seulement l'import : un rapport est abandonné dès qu'un
     * des quatre registres n'a aucune donnée. C'est le comportement d'avant #55,
     * délibérément préservé — il a été vérifié identique avant et après cette
     * phase, sur un foyer ne relevant que ses index d'import.
     *
     * @return array<string, float>
     */
    private static function indexes(float $t1, ?float $t2 = null): array
    {
        return [
            'import_t1' => $t1,
            'import_t2' => $t2 ?? round($t1 / 2, 3),
            'export_t1' => round($t1 / 10, 3),
            'export_t2' => round($t1 / 20, 3),
        ];
    }

    /**
     * Monte le parc de référence : deux compteurs chez le premier compte, un seul
     * portant la somme chez le second, relevés aux MÊMES instants.
     *
     * Les mêmes instants ne sont pas un détail de confort : c'est la condition
     * sous laquelle la somme des interpolations égale l'interpolation de la somme.
     *
     * @return list<string> les instants écrits
     */
    private function seedTwinFleet(): array
    {
        $moments = [
            '2026-05-25 06:00:00',
            '2026-06-10 06:00:00',
            '2026-06-20 06:00:00',
            '2026-07-05 06:00:00',
        ];

        // Index cumulés de chaque compteur à chaque instant, dans l'ordre.
        $a = [
            ['import_t1' => 100.0, 'import_t2' => 50.0, 'export_t1' => 10.0, 'export_t2' => 5.0, 'production' => 200.0],
            ['import_t1' => 118.0, 'import_t2' => 58.0, 'export_t1' => 11.0, 'export_t2' => 5.4, 'production' => 224.0],
            ['import_t1' => 131.0, 'import_t2' => 63.0, 'export_t1' => 11.6, 'export_t2' => 5.7, 'production' => 241.0],
            ['import_t1' => 152.0, 'import_t2' => 72.0, 'export_t1' => 12.8, 'export_t2' => 6.3, 'production' => 272.0],
        ];
        $b = [
            ['import_t1' => 1000.0, 'import_t2' => 500.0, 'export_t1' => 0.0, 'export_t2' => 0.0, 'production' => 0.0],
            ['import_t1' => 1012.0, 'import_t2' => 505.0, 'export_t1' => 0.6, 'export_t2' => 0.2, 'production' => 14.0],
            ['import_t1' => 1021.0, 'import_t2' => 508.0, 'export_t1' => 1.1, 'export_t2' => 0.4, 'production' => 25.0],
            ['import_t1' => 1037.0, 'import_t2' => 514.0, 'export_t1' => 2.0, 'export_t2' => 0.9, 'production' => 46.0],
        ];

        $meterA = $this->newMeter($this->fleetUserId);
        $meterB = $this->newMeter($this->fleetUserId);
        $sum    = $this->newMeter($this->singleUserId);

        foreach ($moments as $i => $at) {
            $this->write($meterA, $at, $a[$i]);
            $this->write($meterB, $at, $b[$i]);

            $totals = [];
            foreach ($a[$i] as $key => $value) {
                $totals[$key] = round($value + $b[$i][$key], 3);
            }
            $this->write($sum, $at, $totals);
        }

        return $moments;
    }


    /**
     * Compare deux résultats de flotte : structure strictement identique, nombres
     * égaux au MILLIÈME — la précision de stockage des index (DECIMAL(12,3)).
     *
     * Pourquoi une tolérance et non l'égalité bit à bit : sommer deux compteurs
     * puis arrondir, ou arrondir un compteur unique portant leur somme, sont deux
     * chemins de calcul flottant différents. Ils coïncident partout, sauf quand la
     * valeur exacte tombe pile sur un demi-millième — le binaire tranche alors
     * d'un côté ou de l'autre. Exiger l'égalité stricte ferait dépendre le test du
     * jeu de données plutôt que de la propriété.
     *
     * Tout ce qui n'est pas un nombre — horodatages, drapeaux `partial`,
     * `solar` à null, jeux de clés — est comparé à l'identique : c'est là que se
     * logeraient les vraies régressions.
     */
    private static function assertFleetMatches(mixed $expected, mixed $actual, string $path = ''): void
    {
        if (is_array($expected)) {
            self::assertIsArray($actual, "Type divergent en {$path}");
            self::assertSame(array_keys($expected), array_keys($actual), "Clés divergentes en {$path}");
            foreach ($expected as $key => $value) {
                self::assertFleetMatches($value, $actual[$key], $path . '/' . $key);
            }

            return;
        }

        if (is_float($expected) || is_int($expected)) {
            self::assertIsNumeric($actual, "Type divergent en {$path}");
            self::assertEqualsWithDelta((float) $expected, (float) $actual, 0.0011, "Valeur divergente en {$path}");

            return;
        }

        self::assertSame($expected, $actual, "Valeur divergente en {$path}");
    }

    // ── L'invariant, méthode de flotte par méthode de flotte ─────────────────

    public function testMonthlyDeltasMatchAnEquivalentSingleMeter(): void
    {
        $this->seedTwinFleet();

        $fleet  = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);
        $single = $this->elec($this->singleUserId)->getMonthlyDeltasForMonth(2026, 6);

        self::assertNotSame([], $fleet, 'Le parc ne rend aucun delta mensuel.');
        self::assertFleetMatches($single, $fleet);
    }

    public function testRangeDeltasMatchAnEquivalentSingleMeter(): void
    {
        $this->seedTwinFleet();

        $from = '2026-06-01 00:00:00';
        $to   = '2026-06-25 00:00:00';

        self::assertFleetMatches(
            $this->elec($this->singleUserId)->getDeltasBetween($from, $to),
            $this->elec($this->fleetUserId)->getDeltasBetween($from, $to),
        );
    }

    public function testBoundaryDeltasMatchAnEquivalentSingleMeter(): void
    {
        $this->seedTwinFleet();

        // Découpage d'une période en sous-périodes tarifaires (#2).
        $boundaries = ['2026-06-01 00:00:00', '2026-06-12 00:00:00', '2026-06-22 00:00:00', '2026-07-01 00:00:00'];

        $fleet = $this->elec($this->fleetUserId)->getDeltasByBoundaries($boundaries);

        self::assertCount(3, $fleet);
        self::assertFleetMatches($this->elec($this->singleUserId)->getDeltasByBoundaries($boundaries), $fleet);
    }

    public function testMonthlySeriesMatchesAnEquivalentSingleMeter(): void
    {
        $this->seedTwinFleet();

        $now = new DateTimeImmutable('2026-07-15 12:00:00', Dates::utc());

        $fleet = $this->elec($this->fleetUserId)->getMonthlyDeltaSeries(4, $now);

        self::assertNotSame([], $fleet);
        self::assertFleetMatches($this->elec($this->singleUserId)->getMonthlyDeltaSeries(4, $now), $fleet);
    }

    public function testHourlyAndQuarterImportDeltasMatchAnEquivalentSingleMeter(): void
    {
        $this->seedTwinFleet();

        $from = new DateTimeImmutable('2026-05-01 00:00:00', Dates::utc());
        $to   = new DateTimeImmutable('2026-08-01 00:00:00', Dates::utc());

        $fleetRepo  = $this->elec($this->fleetUserId);
        $singleRepo = $this->elec($this->singleUserId);

        $hourly = $fleetRepo->getHourlyImportDeltas($from, $to);
        self::assertNotSame([], $hourly);
        self::assertFleetMatches($singleRepo->getHourlyImportDeltas($from, $to), $hourly);

        self::assertFleetMatches(
            $singleRepo->getQuarterImportDeltas($from, $to),
            $fleetRepo->getQuarterImportDeltas($from, $to),
        );
    }

    public function testDailyChartMatchesAnEquivalentSingleMeter(): void
    {
        // Le graphe journalier lit « les N derniers jours » depuis maintenant : les
        // relevés doivent donc être datés relativement à aujourd'hui.
        $meterA = $this->newMeter($this->fleetUserId);
        $meterB = $this->newMeter($this->fleetUserId);
        $sum    = $this->newMeter($this->singleUserId);

        $today = (new DateTimeImmutable('now', Dates::utc()))->setTime(6, 0, 0);

        for ($i = 4; $i >= 0; --$i) {
            $at = $today->modify("-{$i} days")->format('Y-m-d H:i:s');
            $n  = 4 - $i;

            $a = ['import_t1' => 100.0 + 3 * $n, 'import_t2' => 50.0 + $n, 'export_t1' => 10.0 + 0.5 * $n, 'export_t2' => 5.0, 'production' => 200.0 + 6 * $n];
            $b = ['import_t1' => 900.0 + 2 * $n, 'import_t2' => 400.0 + 4 * $n, 'export_t1' => 0.0, 'export_t2' => 1.0 + 0.2 * $n, 'production' => 0.0 + 9 * $n];

            $this->write($meterA, $at, $a);
            $this->write($meterB, $at, $b);

            $totals = [];
            foreach ($a as $key => $value) {
                $totals[$key] = round($value + $b[$key], 3);
            }
            $this->write($sum, $at, $totals);
        }

        $fleet = $this->elec($this->fleetUserId)->getDailyDeltasForChart(7);

        self::assertNotSame([], $fleet);
        self::assertFleetMatches($this->elec($this->singleUserId)->getDailyDeltasForChart(7), $fleet);
    }

    // ── Ce qu'un compteur unique ne peut pas exprimer ────────────────────────

    /**
     * Le cas qui justifie tout le reste : un compteur posé en cours de période ne
     * doit apporter QUE ce qu'il a mesuré depuis sa pose, jamais son odomètre.
     *
     * C'est `interpolateBetween()` qui le garantit — il clampe sur le relevé le
     * plus proche, donc le compteur rend une constante avant son premier relevé,
     * donc un delta nul. Sans ce clamp, le mois encaisserait un saut de 5 000 kWh.
     */
    public function testMeterCommissionedMidPeriodContributesOnlyWhatItMeasured(): void
    {
        $old = $this->newMeter($this->fleetUserId);
        $this->write($old, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($old, '2026-07-01 00:00:00', self::indexes(140.0));

        // Compteur neuf, posé le 20 juin, dont l'odomètre part de 5 000.
        $fresh = $this->newMeter($this->fleetUserId);
        $this->write($fresh, '2026-06-20 00:00:00', self::indexes(5000.0));
        $this->write($fresh, '2026-07-01 00:00:00', self::indexes(5010.0));

        $deltas = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);

        self::assertSame(50.0, $deltas['prelev_jour'], '40 kWh du compteur historique + 10 du compteur neuf.');
        self::assertNotEquals(5050.0, $deltas['prelev_jour'], "L'odomètre du compteur neuf a été compté comme consommation.");
    }

    /**
     * Un compteur déclaré mais jamais relevé — ce que la page /meters permet de
     * créer — contribue 0. Il ne doit surtout pas annuler le rapport : avant #55,
     * un registre sans valeur faisait retourner un tableau vide.
     */
    public function testMeterWithoutAnyReadingContributesZeroInsteadOfCancellingTheReport(): void
    {
        $live = $this->newMeter($this->fleetUserId);
        $this->write($live, '2026-06-01 00:00:00', self::indexes(100.0, 20.0));
        $this->write($live, '2026-07-01 00:00:00', self::indexes(140.0, 25.0));

        // Registres créés, aucun relevé.
        (new MeterTopology($this->pdo()))->ensureRegisters($this->newMeter($this->fleetUserId));

        $deltas = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);

        self::assertNotSame([], $deltas, 'Un compteur muet a annulé tout le rapport.');
        self::assertSame(40.0, $deltas['prelev_jour']);
        self::assertSame(5.0, $deltas['prelev_nuit']);
    }

    /**
     * `data_from` / `data_to` décrivent la fenêtre couverte par TOUS les compteurs
     * — l'intersection, pas l'union. Elles pilotent `coverage_complete` : un
     * compteur qui a cessé d'être relevé doit dégrader la couverture, pas la
     * laisser croire complète.
     */
    public function testCoverageWindowIsTheIntersectionOfTheFleet(): void
    {
        $full = $this->newMeter($this->fleetUserId);
        $this->write($full, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($full, '2026-06-30 00:00:00', self::indexes(140.0));

        // Relevé plus tard, et arrêté plus tôt.
        $partial = $this->newMeter($this->fleetUserId);
        $this->write($partial, '2026-06-10 00:00:00', self::indexes(10.0));
        $this->write($partial, '2026-06-20 00:00:00', self::indexes(14.0));

        $deltas = $this->elec($this->fleetUserId)->getDeltasBetween('2026-06-01 00:00:00', '2026-07-01 00:00:00');

        self::assertSame('2026-06-10 00:00:00', $deltas['data_from'], 'Début le plus TARDIF du parc.');
        self::assertSame('2026-06-20 00:00:00', $deltas['data_to'], 'Fin la plus PRÉCOCE du parc.');
        // `to` décrit au contraire jusqu'où le rapport porte : la fin la plus tardive.
        self::assertSame('2026-06-30 00:00:00', $deltas['to']);
    }

    /**
     * Un compteur FERMÉ avant la période n'a plus rien à y couvrir : sa dernière
     * date ne doit plus borner `data_to` (#76).
     *
     * C'est le bug de l'issue, bout en bout : remplacer son compteur — fermer
     * l'ancien, déclarer le neuf — figeait la fenêtre couverte à la date du
     * dernier relevé du compteur retiré, et donc `coverage_complete` à `false`
     * pour tous les mois suivants, indéfiniment.
     */
    public function testAMeterClosedBeforeThePeriodNoLongerFreezesCoverage(): void
    {
        $retired = $this->newMeter($this->fleetUserId, 'electricity', '2026-06-15');
        $this->write($retired, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($retired, '2026-06-14 00:00:00', self::indexes(120.0));

        $successor = $this->newMeter($this->fleetUserId);
        $this->write($successor, '2026-06-15 00:00:00', self::indexes(0.0));
        $this->write($successor, '2026-07-31 00:00:00', self::indexes(60.0));

        $deltas = $this->elec($this->fleetUserId)->getDeltasBetween('2026-07-01 00:00:00', '2026-08-01 00:00:00');

        self::assertSame(
            '2026-07-31 00:00:00',
            $deltas['data_to'],
            'La fin du compteur RETIRÉ borne encore la couverture de juillet.',
        );
    }

    /**
     * Mois du remplacement : le compteur retiré est relevé jusqu'à la veille de sa
     * fermeture (borne EXCLUE, il ne peut pas l'être le jour même) et le
     * successeur prend le relais. Le parc n'a pas eu de trou.
     */
    public function testAClosedMeterHandedOverMidMonthKeepsTheCoverageComplete(): void
    {
        $retired = $this->newMeter($this->fleetUserId, 'electricity', '2026-06-15');
        $this->write($retired, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($retired, '2026-06-14 00:00:00', self::indexes(120.0));

        $successor = $this->newMeter($this->fleetUserId);
        $this->write($successor, '2026-06-15 00:00:00', self::indexes(0.0));
        $this->write($successor, '2026-06-30 00:00:00', self::indexes(30.0));

        $deltas = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);

        self::assertSame('2026-06-30 00:00:00', $deltas['data_to'], 'Le relais du successeur n’a pas été vu.');
    }

    /**
     * Fermeture SANS successeur : plus rien n'est attendu, mais plus rien n'arrive
     * non plus. La couverture doit rester dégradée — un foyer qui ferme son
     * dernier compteur ne doit pas voir un coût partiel annoncé complet.
     */
    public function testAClosureWithoutSuccessorStillDegradesCoverage(): void
    {
        $retired = $this->newMeter($this->fleetUserId, 'electricity', '2026-06-15');
        $this->write($retired, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($retired, '2026-06-14 00:00:00', self::indexes(120.0));

        $deltas = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);

        self::assertSame('2026-06-14 00:00:00', $deltas['data_to'], 'Une fermeture sans relais a blanchi la couverture.');
    }

    /**
     * La production reste `null` tant qu'AUCUN compteur ne la mesure, et devient
     * un nombre dès qu'un seul le fait. Un `0.0` ici se lirait comme une
     * production effondrée, là où il n'y a qu'une absence de panneaux.
     */
    public function testSolarStaysNullOnlyWhenNoMeterMeasuresIt(): void
    {
        $plain = $this->newMeter($this->fleetUserId);
        $this->write($plain, '2026-06-01 00:00:00', self::indexes(100.0));
        $this->write($plain, '2026-07-01 00:00:00', self::indexes(140.0));

        self::assertNull($this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6)['solar']);

        // Second compteur, celui-ci équipé de panneaux.
        $pv = $this->newMeter($this->fleetUserId);
        $this->write($pv, '2026-06-01 00:00:00', self::indexes(10.0) + ['production' => 500.0]);
        $this->write($pv, '2026-07-01 00:00:00', self::indexes(12.0) + ['production' => 620.0]);

        $deltas = $this->elec($this->fleetUserId)->getMonthlyDeltasForMonth(2026, 6);
        self::assertSame(120.0, $deltas['solar']);
        self::assertSame('kwh', $deltas['solar_unit']);
    }

    /**
     * Le drapeau `native` autorise la facturation au quart d'heure. Un créneau
     * alimenté par deux compteurs n'est natif que si les DEUX sont au pas de
     * 15 min : présenter comme mesuré un créneau dont une part a été étalée
     * facturerait une répartition comme une mesure.
     */
    public function testQuarterSlotIsNotNativeWhenOneMeterIsHourly(): void
    {
        $quarterly = $this->newMeter($this->fleetUserId);
        foreach ([0, 15, 30, 45, 60] as $i => $minutes) {
            $at = (new DateTimeImmutable('2026-06-01 10:00:00', Dates::utc()))
                ->modify("+{$minutes} minutes")
                ->format('Y-m-d H:i:s');
            $this->write($quarterly, $at, self::indexes(100.0 + $i));
        }

        $hourly = $this->newMeter($this->fleetUserId);
        $this->write($hourly, '2026-06-01 10:00:00', self::indexes(500.0));
        $this->write($hourly, '2026-06-01 11:00:00', self::indexes(508.0));

        $quarters = $this->elec($this->fleetUserId)->getQuarterImportDeltas(
            new DateTimeImmutable('2026-06-01 09:00:00', Dates::utc()),
            new DateTimeImmutable('2026-06-01 12:00:00', Dates::utc()),
        );

        self::assertNotSame([], $quarters);
        foreach ($quarters as $quarter) {
            if ($quarter['quarter'] === '2026-06-01 10:00:00') {
                self::assertFalse($quarter['native'], 'Le créneau mêle une mesure et un étalement.');

                return;
            }
        }

        self::fail('Le créneau de 10:00 est absent du résultat.');
    }

    // ── Gaz / eau ────────────────────────────────────────────────────────────

    /**
     * Même invariant côté fluides : la série rendue par deux compteurs relevés aux
     * mêmes instants doit être celle d'un compteur unique portant la somme.
     */
    public function testUtilitySeriesMatchesAnEquivalentSingleMeter(): void
    {
        $moments = ['2026-05-28 08:00:00', '2026-06-08 08:00:00', '2026-06-21 08:00:00', '2026-07-03 08:00:00'];
        $a       = [100.0, 118.5, 131.0, 152.5];
        $b       = [40.0, 44.0, 47.5, 53.0];

        $meterA = $this->newMeter($this->fleetUserId, 'gas');
        $meterB = $this->newMeter($this->fleetUserId, 'gas');
        $sum    = $this->newMeter($this->singleUserId, 'gas');

        foreach ($moments as $i => $at) {
            $this->writeUtility($meterA, $this->fleetUserId, 'gas', $at, $a[$i]);
            $this->writeUtility($meterB, $this->fleetUserId, 'gas', $at, $b[$i]);
            $this->writeUtility($sum, $this->singleUserId, 'gas', $at, round($a[$i] + $b[$i], 3));
        }

        $fleet  = new UtilityReadingRepository($this->pdo(), $this->fleetUserId, 'gas');
        $single = new UtilityReadingRepository($this->pdo(), $this->singleUserId, 'gas');

        $range = $fleet->getReadingsForRange('2026-06-01 00:00:00', '2026-07-01 00:00:00');
        self::assertNotSame([], $range);
        self::assertFleetMatches($single->getReadingsForRange('2026-06-01 00:00:00', '2026-07-01 00:00:00'), $range);

        self::assertFleetMatches($single->getLastTwoReadings(), $fleet->getLastTwoReadings());
    }

    /**
     * Compteurs relevés à des instants DIFFÉRENTS : la série rendue est l'union
     * des horodatages, et chaque compteur y est interpolé — jamais absent, ce qui
     * ferait chuter l'index cumulé et produirait un delta négatif.
     */
    public function testUtilitySeriesInterleavesMetersWithoutDroppingAnIndex(): void
    {
        $meterA = $this->newMeter($this->fleetUserId, 'water');
        $meterB = $this->newMeter($this->fleetUserId, 'water');

        $this->writeUtility($meterA, $this->fleetUserId, 'water', '2026-06-01 00:00:00', 100.0);
        $this->writeUtility($meterA, $this->fleetUserId, 'water', '2026-06-30 00:00:00', 130.0);
        $this->writeUtility($meterB, $this->fleetUserId, 'water', '2026-06-15 00:00:00', 50.0);
        $this->writeUtility($meterB, $this->fleetUserId, 'water', '2026-06-25 00:00:00', 60.0);

        $rows = (new UtilityReadingRepository($this->pdo(), $this->fleetUserId, 'water'))
            ->getReadingsForRange('2026-06-01 00:00:00', '2026-07-01 00:00:00');

        self::assertSame(
            ['2026-06-01 00:00:00', '2026-06-15 00:00:00', '2026-06-25 00:00:00', '2026-06-30 00:00:00'],
            array_column($rows, 'reading_at'),
        );

        // Série strictement croissante : c'est la propriété qui rend les deltas
        // exploitables en aval.
        $previous = null;
        foreach ($rows as $row) {
            if ($previous !== null) {
                self::assertGreaterThan($previous, $row['counter_m3'], 'Index cumulé en recul.');
            }
            $previous = $row['counter_m3'];
        }

        // Au 1er juin, B est clampé à son premier relevé (50) : 100 + 50.
        self::assertSame(150.0, $rows[0]['counter_m3']);
        // Au 30 juin, B est clampé à son dernier relevé (60) : 130 + 60.
        self::assertSame(190.0, $rows[3]['counter_m3']);
    }

    private function writeUtility(int $meterId, int $userId, string $energyType, string $at, float $m3): void
    {
        $this->pdo()->prepare(
            'INSERT INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
             VALUES (:uid, :mid, :etype, :a, :v)'
        )->execute(['uid' => $userId, 'mid' => $meterId, 'etype' => $energyType, 'a' => $at, 'v' => $m3]);
    }
}
