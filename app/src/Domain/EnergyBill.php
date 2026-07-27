<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Montant énergie réellement facturé sur un mois calendaire (#229).
 *
 * Ne porte QUE la part énergie, pas le total de la facture : le coefficient et l'offset
 * du contrat ne pilotent que ce poste. Les coûts réseau, taxes et abonnements sont
 * indépendants de la formule — les inclure attribuerait leur écart au coefficient.
 *
 * Les deux montants sont optionnels et non exclusifs : une facture donne parfois le HTVA,
 * parfois le TTC, souvent les deux. La résolution travaille en TTC (le calcul de
 * l'application l'est aussi), d'où {@see ttcOrDerived()} qui comble le trou avec le taux
 * TVA de la grille de la période plutôt que de figer une conversion en base.
 *
 * Value object immuable, sans dépendance.
 */
final class EnergyBill
{
    /**
     * Plafond de saisie d'un montant, dicté par la colonne `DECIMAL(12,4)` et non par une
     * hypothèse sur les dépenses des utilisateurs — un plafond « raisonnable » rognerait
     * tôt ou tard un usage légitime (immeuble collectif, compteur professionnel, pic de
     * prix). À cent millions d'unités monétaires pour une part énergie mensuelle, la borne
     * ne peut rejeter que des saisies erronées.
     *
     * Elle n'est pas cosmétique : au-delà, la base refuse l'écriture avec un
     * `SQLSTATE[22003]` anglais qui remonterait tel quel dans le bandeau d'erreur — et
     * sur un serveur sans `STRICT_TRANS_TABLES`, elle ne refuserait rien du tout, tronquant
     * silencieusement au maximum de la colonne. Valider ici rend le comportement
     * déterministe et le message traduit, quel que soit le `sql_mode` du serveur.
     */
    public const MAX_AMOUNT = 99999999.9999;

    public function __construct(
        public readonly int $id,
        public readonly string $energyType,
        public readonly int $year,
        public readonly int $month,
        public readonly ?float $amountHtva = null,
        public readonly ?float $amountTtc = null,
        public readonly string $note = '',
    ) {
    }

    /**
     * Montant TTC exploitable pour la résolution : le TTC saisi s'il existe, sinon le
     * HTVA converti au taux de la grille. `null` si rien n'a été saisi.
     *
     * Le TTC saisi prime volontairement sur le HTVA converti : c'est la valeur lue sur la
     * facture, alors que la conversion suppose que le taux de la grille est bien celui
     * qu'a appliqué le fournisseur.
     *
     * @param float $vatRatePercent Taux TVA en POURCENTAGE (21.0), cf. tariff_grids.vat_rate.
     */
    public function ttcOrDerived(float $vatRatePercent): ?float
    {
        if ($this->amountTtc !== null) {
            return $this->amountTtc;
        }

        if ($this->amountHtva !== null) {
            return $this->amountHtva * (1.0 + $vatRatePercent / 100.0);
        }

        return null;
    }

    /** Clé de période 'YYYY-MM', pour trier et rapprocher sans manipuler deux entiers. */
    public function periodKey(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
