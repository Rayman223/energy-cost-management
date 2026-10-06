<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Accès aux profils de charge servant à pondérer les cotations (#93).
 *
 * Seam distincte de {@see DynamicPriceRepositoryInterface} malgré une forme voisine :
 * les deux séries ont la même convention de clé (instant UTC) et se joignent par
 * égalité, mais l'une porte un prix et l'autre un poids. Les mêler dans un seul dépôt
 * reviendrait à dire qu'un profil est un prix.
 */
interface LoadProfileRepositoryInterface
{
    /**
     * Poids du profil sur `[$from, $to[`.
     *
     * Les valeurs sont rendues **brutes**, sans normalisation : le calcul en fait une
     * moyenne pondérée, qui divise par la somme des poids — seule leur pondération
     * relative compte, et normaliser ne ferait qu'ajouter une perte de précision.
     *
     * Map vide si le profil est absent sur la fenêtre : l'appelant descend alors d'un
     * cran dans sa cascade, il ne doit jamais inventer de poids.
     *
     * @return array<string, float> 'Y-m-d H:i:00' UTC => poids
     */
    public function weightsBetween(
        string $code,
        string $country,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $resolutionMin = 15,
    ): array;

    /**
     * Codes disponibles pour ce pays, par ordre alphabétique — alimente le sélecteur
     * de la page des tarifs. Vide si aucun profil n'a été importé.
     *
     * @return list<string>
     */
    public function availableCodes(string $country): array;
}
