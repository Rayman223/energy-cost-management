<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Dates;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un compteur du parc de l'utilisateur (#55) : électricité, gaz ou eau.
 *
 * Jusqu'ici l'application supposait UN compteur par énergie. La table `meters`
 * porte désormais les trois énergies et un utilisateur peut en déclarer
 * plusieurs par énergie — maison + atelier, ou un compteur d'eau remplacé en
 * cours d'année.
 *
 * **L'énergie est immuable après création.** Elle n'est pas un attribut
 * d'affichage : elle décide de quelle table vient chaque relevé (`meter_readings`
 * via les registres pour l'électricité, `utility_readings` pour le gaz et l'eau).
 * La changer sur un compteur qui a des index rendrait sa série incohérente sans
 * que rien ne le signale.
 *
 * **Le libellé peut être vide, et c'est un état normal**, pas un oubli : un
 * compteur sans nom est désigné par un libellé dérivé À L'AFFICHAGE, dans la
 * langue du lecteur (cf. {@see defaultLabelKey()}). Écrire « Compteur
 * électrique » en base — ce que faisait `MeterTopology` avant #55 — le servait
 * tel quel à un lecteur néerlandophone et le figeait pour toujours.
 *
 * `closedOn` est une borne de fin EXCLUE — premier jour hors service (#1,
 * cf. app/docs/date-bounds.md), comme `Battery::decommissionedOn`. Elle est
 * portée ici dès maintenant mais n'est pas encore opposée aux écritures : c'est
 * l'objet de la phase suivante.
 *
 * Les colonnes `country` et `timezone` de la table ne sont pas reprises : plus
 * aucun code ne les lit depuis le passage au modèle à registres. Les faire
 * remonter ici laisserait croire qu'elles pilotent quelque chose.
 *
 * Value object immuable, sans dépendance à la base.
 */
final class Meter
{
    /** Longueur du libellé libre, alignée sur la colonne. */
    public const MAX_LABEL = 120;

    /**
     * Énergies portées par `meters` — miroir de l'ENUM `energy_type`, et source
     * unique de la liste : {@see \App\Infrastructure\MeterTopology::ENERGIES}
     * pointe ici plutôt que de la redéclarer.
     *
     * @var list<string>
     */
    public const ENERGIES = ['electricity', 'gas', 'water'];

    public function __construct(
        public readonly int $id,
        public readonly string $energyType,
        public readonly string $label = '',
        public readonly ?DateTimeImmutable $closedOn = null,
    ) {
        // Garde-fou de dernier recours : une énergie inconnue ne vient jamais
        // d'une saisie (la route valide avant, avec un message traduit) mais d'un
        // appelant fautif. Sans elle, `defaultLabelKey()` fabriquerait une clé
        // absente du catalogue, qui s'afficherait telle quelle à l'écran.
        if (!self::isEnergy($energyType)) {
            throw new InvalidArgumentException('Unknown energy type: ' . $energyType);
        }
    }

    /** Cette chaîne est-elle une énergie connue de `meters` ? */
    public static function isEnergy(string $energyType): bool
    {
        return in_array($energyType, self::ENERGIES, true);
    }

    /** L'utilisateur a-t-il nommé ce compteur ? Sinon, son libellé est dérivé. */
    public function isNamed(): bool
    {
        return trim($this->label) !== '';
    }

    /**
     * Clé de traduction du libellé par défaut (« Électricité », « Gaz », « Eau »),
     * à utiliser quand {@see isNamed()} est faux. La traduction est faite par
     * l'appelant, qui seul connaît la langue du lecteur.
     */
    public function defaultLabelKey(): string
    {
        return 'meters.default_label.' . $this->energyType;
    }

    /** Clé de traduction du nom de l'énergie, pour les colonnes et les listes. */
    public function energyLabelKey(): string
    {
        return 'meters.energy.' . $this->energyType;
    }

    /**
     * Compteur fermé à cette date ? Borne EXCLUE : le jour de fermeture est le
     * premier jour NON couvert (#1). Même sémantique que
     * {@see Battery::isDecommissionedOn()}.
     */
    public function isClosedOn(DateTimeImmutable $date): bool
    {
        return $this->closedOn !== null
            && $this->closedOn->setTime(0, 0, 0) <= $date->setTime(0, 0, 0);
    }

    /**
     * Reconstruit un compteur depuis une ligne de `meters`.
     *
     * La DATE revient en 'Y-m-d' : parsée en UTC, fuseau de stockage du projet,
     * pour que la comparaison avec les bornes de période porte sur le même
     * référentiel (même choix que {@see Battery::fromRow()}).
     *
     * @param array{id: int|string, energy_type: string, label: string, closed_on: ?string} $row
     */
    public static function fromRow(array $row): self
    {
        $closedOn = $row['closed_on'];

        return new self(
            id:         (int) $row['id'],
            energyType: $row['energy_type'],
            label:      $row['label'],
            closedOn:   $closedOn !== null ? new DateTimeImmutable($closedOn . ' 00:00:00', Dates::utc()) : null,
        );
    }
}
