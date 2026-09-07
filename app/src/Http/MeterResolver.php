<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Meter;
use App\Repository\MeterRepository;

/**
 * Compteur visé par une requête d'écriture ou d'historique (#55).
 *
 * Calqué sur la résolution de batterie de {@see \App\Http\Controller\BatteryReadingController}
 * — même problème, mêmes règles :
 *
 *   - `meter_id` explicite → doit exister, appartenir à l'utilisateur ET porter
 *     la bonne énergie ;
 *   - absent avec UN seul compteur → celui-là. C'est le cas de tout le parc
 *     existant, et exiger un identifiant obligerait chaque agent d'ingestion à
 *     connaître une clé de base de données ;
 *   - absent avec PLUSIEURS compteurs → refus, avec la liste des identifiants.
 *     **Jamais de devinette** : écrire dans le mauvais compteur produirait un
 *     saut d'index que rien ne signalerait, et que la validation de bornes
 *     lirait comme une consommation légitime ;
 *   - absent SANS aucun compteur → `null`, et le repository crée le compteur à
 *     la volée comme il l'a toujours fait. C'est le chemin d'un compte neuf :
 *     refuser ici obligerait à passer par /meters avant la première saisie, ce
 *     qui casserait la prise en main.
 */
final class MeterResolver
{
    public function __construct(private readonly MeterRepository $meters)
    {
    }

    /**
     * @param mixed  $raw        Valeur brute de `meter_id` (absente, vide, ou entier).
     * @param string $energyType Énergie attendue ; un compteur d'une autre énergie
     *                           est refusé comme s'il était inconnu — c'est bien un
     *                           identifiant invalide POUR CETTE ROUTE.
     * @return int|null Compteur visé, ou null pour laisser le repository résoudre
     *                  (et créer) son compteur par défaut.
     *
     * @throws ValidationException identifiant inconnu, ou ambiguïté non tranchée
     */
    public function resolve(mixed $raw, string $energyType): ?int
    {
        if ($raw !== null && $raw !== '') {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new ValidationException('Unknown meter_id');
            }

            $meter = $this->meters->find($id);
            // Un compteur d'autrui et un compteur d'une autre énergie donnent le
            // MÊME message : distinguer les deux dirait à un attaquant quels
            // identifiants existent.
            if ($meter === null || $meter->energyType !== $energyType) {
                throw new ValidationException('Unknown meter_id');
            }

            return $id;
        }

        $fleet = $this->meters->listByEnergy($energyType);
        if ($fleet === []) {
            return null;
        }
        if (count($fleet) > 1) {
            $ids = implode(', ', array_map(static fn (Meter $meter): string => (string) $meter->id, $fleet));

            throw new ValidationException('meter_id is required (several ' . $energyType . ' meters: ' . $ids . ')');
        }

        return $fleet[0]->id;
    }
}
