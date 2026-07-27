<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Résultat du rapprochement facture (#229) : le couple (coefficient, offset) déduit des
 * montants facturés, et surtout la QUALITÉ de cette déduction.
 *
 * Le mode est la première chose à lire — proposer un couple sans dire s'il est déterminé
 * ou seulement plausible induirait en erreur, puisque `single_period` et `ill_conditioned`
 * admettent une infinité de solutions également valides.
 *
 * Value object immuable, sans dépendance.
 */
final class SpotFormulaFit
{
    /** Aucune période exploitable : pas de facture saisie, ou aucune heure couverte par un prix de marché. */
    public const MODE_UNDETERMINED = 'undetermined';

    /** Une seule période : une équation, deux inconnues → seules les propositions alternatives ont un sens. */
    public const MODE_SINGLE_PERIOD = 'single_period';

    /** Plusieurs périodes, mais aux prix moyens trop proches : le système est dégénéré. */
    public const MODE_ILL_CONDITIONED = 'ill_conditioned';

    /** Deux périodes bien conditionnées : solution exacte du système 2×2. */
    public const MODE_EXACT = 'exact';

    /** Trois périodes ou plus : moindres carrés, l'écart résiduel indique la qualité. */
    public const MODE_LEAST_SQUARES = 'least_squares';

    /**
     * Tous les modes, dans l'ordre croissant de détermination. Sert de source unique au
     * garde-fou i18n (TemplateCatalogTest dérive `reconciliation.mode.<mode>`) : ajouter
     * un mode sans sa traduction rend la CI rouge.
     *
     * @var list<string>
     */
    public const MODES = [
        self::MODE_UNDETERMINED,
        self::MODE_SINGLE_PERIOD,
        self::MODE_ILL_CONDITIONED,
        self::MODE_EXACT,
        self::MODE_LEAST_SQUARES,
    ];

    /**
     * @param string     $mode                       Une des constantes MODE_*.
     * @param float|null $coefficient                Coefficient résolu ; null si le système n'est pas déterminé.
     * @param float|null $offsetTtc                  Offset €/kWh TTC résolu ; null si le système n'est pas déterminé.
     * @param float|null $offsetAtCurrentCoefficient Proposition alternative : l'offset qui annule l'écart en
     *        gardant le coefficient actuellement saisi. Toujours disponible dès qu'un kWh est couvert, y
     *        compris dans les modes non déterminés — c'est la sortie utile du cas « un seul mois ».
     * @param float|null $coefficientAtCurrentOffset Proposition alternative symétrique, à offset figé.
     * @param float      $priceSpreadPct             Écart relatif entre le plus haut et le plus bas prix moyen
     *        indexé des périodes, en %. Mesure le conditionnement : sous le seuil du fitter, les équations
     *        sont colinéaires et le couple ne peut pas être séparé.
     * @param float|null $residualTtc                Somme des |écarts| restants après application du couple.
     *        0 en mode exact ; en moindres carrés, ce qui n'est pas explicable par la formule.
     * @param bool       $coefficientOutOfBounds     Le coefficient résolu sort de ]0 ; 5]. L'écart ne vient
     *        alors pas de la formule (montant saisi incluant autre chose que l'énergie, poste réseau mal
     *        saisi) : la valeur est renvoyée pour diagnostic, mais ne doit pas être saisie telle quelle.
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?float $coefficient = null,
        public readonly ?float $offsetTtc = null,
        public readonly ?float $offsetAtCurrentCoefficient = null,
        public readonly ?float $coefficientAtCurrentOffset = null,
        public readonly float $priceSpreadPct = 0.0,
        public readonly ?float $residualTtc = null,
        public readonly bool $coefficientOutOfBounds = false,
    ) {
    }

    /** Le système admet-il une solution unique ? false = seules les propositions alternatives sont exploitables. */
    public function isDetermined(): bool
    {
        return $this->coefficient !== null && $this->offsetTtc !== null;
    }
}
