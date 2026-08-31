<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;

/**
 * Une batterie domestique du parc de l'utilisateur (#26) : le matériel, son
 * investissement, et les deux hypothèses qui rendent son bilan calculable.
 *
 * Pourquoi une entité à part et non des registres sur le compteur : ce que la
 * batterie a fait économiser ne se lit sur aucun index du compteur — `import_t1/t2`
 * mesurent déjà le réseau APRÈS son intervention. L'économie est contrefactuelle,
 * elle se reconstruit depuis les kWh entrés et sortis de la batterie elle-même,
 * qui ont besoin d'un porteur : capacité, prix d'achat, date de mise en service.
 *
 * Les deux hypothèses portées ici sont assumées comme telles — ni l'une ni l'autre
 * ne se mesure :
 *   - `pvChargeShare` : d'où vient l'énergie chargée (photovoltaïque ou réseau).
 *     Elle décide si charger la batterie a coûté un manque à gagner d'injection
 *     ou un import payé plein tarif ;
 *   - `dischargeProfile` : à quel tarif l'énergie déchargée aurait été facturée
 *     (cf. {@see BatteryDischargeProfile}).
 *
 * `decommissionedOn` est une borne de fin EXCLUE — premier jour hors service (#1,
 * cf. app/docs/date-bounds.md), comme `valid_to` sur une grille ou un barème.
 *
 * Value object immuable, sans dépendance à la base.
 */
final class Battery
{
    /**
     * Plafonds de saisie dictés par les colonnes, pas par une hypothèse sur le
     * marché — même raisonnement que {@see AdvanceSchedule::MAX_AMOUNT} : sans eux,
     * une valeur démesurée n'est refusée qu'au niveau SQL (message anglais brut),
     * voire tronquée en silence sur un serveur sans `STRICT_TRANS_TABLES`.
     */
    public const MAX_CAPACITY_KWH   = 99999.999;   // DECIMAL(8,3)
    public const MAX_PURCHASE_PRICE = 99999999.99; // DECIMAL(10,2)
    public const MAX_RATED_CYCLES   = 65535;       // SMALLINT UNSIGNED

    /** Longueur des champs libres, alignée sur les colonnes. */
    public const MAX_BRAND = 80;
    public const MAX_MODEL = 120;
    public const MAX_NOTE  = 255;

    public function __construct(
        public readonly int $id,
        public readonly string $brand,
        public readonly string $model,
        public readonly float $capacityKwh,
        public readonly DateTimeImmutable $commissionedOn,
        public readonly ?DateTimeImmutable $decommissionedOn = null,
        public readonly ?float $usableCapacityKwh = null,
        public readonly ?float $purchasePrice = null,
        public readonly ?DateTimeImmutable $warrantyUntil = null,
        public readonly ?int $ratedCycles = null,
        public readonly int $pvChargeShare = 100,
        public readonly BatteryDischargeProfile $dischargeProfile = BatteryDischargeProfile::ImportMix,
        public readonly ?int $dischargeT1Share = null,
        public readonly string $note = '',
    ) {
    }

    /**
     * Libellé lisible : « Marque Modèle », ou l'un des deux, ou la capacité en
     * dernier recours. Marque et modèle sont facultatifs — un onduleur générique
     * n'en a pas toujours — mais une batterie sans nom du tout resterait
     * indésignable dans une liste.
     */
    public function label(): string
    {
        $parts = array_filter([trim($this->brand), trim($this->model)], static fn (string $p): bool => $p !== '');

        return $parts === []
            ? rtrim(rtrim(number_format($this->capacityKwh, 3, '.', ''), '0'), '.') . ' kWh'
            : implode(' ', $parts);
    }

    /**
     * Capacité retenue pour les indicateurs : l'utile si elle est renseignée,
     * sinon la nominale. Une batterie annoncée 10 kWh dont 9,2 sont exploitables
     * ne cycle jamais sur 10 : c'est la capacité utile qui a un sens physique.
     */
    public function effectiveCapacityKwh(): float
    {
        return $this->usableCapacityKwh ?? $this->capacityKwh;
    }

    /**
     * Batterie en service à cette date ? Intervalle `[commissionedOn, decommissionedOn[`,
     * la borne de fin étant le premier jour NON couvert (#1).
     */
    public function isInServiceOn(DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0, 0);

        if ($day < $this->commissionedOn->setTime(0, 0, 0)) {
            return false;
        }

        return $this->decommissionedOn === null || $day < $this->decommissionedOn->setTime(0, 0, 0);
    }

    /**
     * Batterie déposée à cette date. Distinct de `!isInServiceOn()` : une batterie
     * dont la mise en service est à venir n'est pas non plus en service, sans être
     * déposée pour autant.
     */
    public function isDecommissionedOn(DateTimeImmutable $date): bool
    {
        return $this->decommissionedOn !== null
            && $this->decommissionedOn->setTime(0, 0, 0) <= $date->setTime(0, 0, 0);
    }

    /** Garantie constructeur expirée à cette date (informatif). */
    public function isOutOfWarrantyOn(DateTimeImmutable $date): bool
    {
        return $this->warrantyUntil !== null
            && $this->warrantyUntil->setTime(0, 0, 0) <= $date->setTime(0, 0, 0);
    }

    /**
     * Part de la charge venant du photovoltaïque, en fraction [0, 1] — la forme
     * attendue par le calcul, là où la saisie et le stockage sont en pourcentage.
     */
    public function pvChargeRatio(): float
    {
        return $this->clampPercent($this->pvChargeShare) / 100.0;
    }

    /** Part complémentaire, prélevée au réseau pour charger la batterie. */
    public function gridChargeRatio(): float
    {
        return 1.0 - $this->pvChargeRatio();
    }

    /**
     * Part T1 déclarée pour le profil {@see BatteryDischargeProfile::Ratio}, en
     * fraction [0, 1]. `null` pour tous les autres profils : leur part T1 se
     * résout au moment du calcul (mix réel observé) ou vaut 0/1 par construction —
     * la renvoyer ici laisserait croire qu'elle a été saisie.
     */
    public function dischargeT1Ratio(): ?float
    {
        if (!$this->dischargeProfile->requiresT1Share() || $this->dischargeT1Share === null) {
            return null;
        }

        return $this->clampPercent($this->dischargeT1Share) / 100.0;
    }

    /** Période de service lisible : '2026-01-01 → 2026-12-31' ou '2026-01-01 → …'. */
    public function serviceLabel(): string
    {
        return $this->commissionedOn->format('Y-m-d') . ' → ' . ($this->decommissionedOn?->format('Y-m-d') ?? '…');
    }

    /**
     * Reconstruit une batterie depuis une ligne de `batteries`.
     *
     * Les DATE reviennent en 'Y-m-d' : parsées en UTC, fuseau de stockage du
     * projet, pour que la comparaison avec les bornes de période porte sur le même
     * référentiel (même choix que {@see AdvanceSchedule::fromRow()}).
     *
     * @param array{
     *     id: int|string, brand: string, model: string, capacity_kwh: string|float,
     *     usable_capacity_kwh: string|float|null, purchase_price: string|float|null,
     *     commissioned_on: string, decommissioned_on: ?string, warranty_until: ?string,
     *     rated_cycles: int|string|null, pv_charge_share: int|string,
     *     discharge_profile: string, discharge_t1_share: int|string|null, note: string
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                 (int) $row['id'],
            brand:              $row['brand'],
            model:              $row['model'],
            capacityKwh:        (float) $row['capacity_kwh'],
            commissionedOn:     self::parseDate($row['commissioned_on']),
            decommissionedOn:   self::parseNullableDate($row['decommissioned_on']),
            usableCapacityKwh:  $row['usable_capacity_kwh'] !== null ? (float) $row['usable_capacity_kwh'] : null,
            purchasePrice:      $row['purchase_price'] !== null ? (float) $row['purchase_price'] : null,
            warrantyUntil:      self::parseNullableDate($row['warranty_until']),
            ratedCycles:        $row['rated_cycles'] !== null ? (int) $row['rated_cycles'] : null,
            pvChargeShare:      (int) $row['pv_charge_share'],
            dischargeProfile:   BatteryDischargeProfile::fromStringOrDefault($row['discharge_profile']),
            dischargeT1Share:   $row['discharge_t1_share'] !== null ? (int) $row['discharge_t1_share'] : null,
            note:               $row['note'],
        );
    }

    /**
     * Pourcentage ramené dans [0, 100]. La saisie est déjà validée en amont ; ce
     * clamp protège le CALCUL d'une ligne écrite hors application (import SQL,
     * migration manuelle) — un ratio hors bornes produirait une économie négative
     * ou supérieure à l'énergie réellement déchargée, sans rien signaler.
     */
    private function clampPercent(int $percent): float
    {
        return (float) max(0, min(100, $percent));
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value . ' 00:00:00', Dates::utc());
    }

    private static function parseNullableDate(?string $value): ?DateTimeImmutable
    {
        return $value !== null ? self::parseDate($value) : null;
    }
}
