<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Pondération employée pour réduire les cotations d'un mois à UN prix unitaire (#93).
 *
 * Un contrat à prix variable est facturé `X × Belpex_RLP_M + Y`, où Belpex_RLP_M est la
 * moyenne des cotations day-ahead du mois **pondérée par un profil de consommation** —
 * pas une moyenne arithmétique. La pondération n'est donc pas un détail d'implémentation
 * mais une composante du prix : deux pondérations différentes donnent deux factures
 * différentes sur les mêmes cotations. D'où son exposition explicite jusqu'à l'UI.
 *
 * Les trois valeurs sont ordonnées de la plus fidèle à la plus grossière.
 */
enum LoadWeighting: string
{
    /**
     * Courbe de charge réelle de l'utilisateur, au pas de 15 min.
     *
     * Le plus juste physiquement : c'est SA consommation qui pondère SES prix. La
     * formule étant affine, ce cas redonne exactement le total d'une facturation
     * créneau par créneau — un contrat `indexed_monthly` et un `dynamic_quarter`
     * coïncident alors au centime près.
     */
    case ActualLoad = 'actual_load';

    /**
     * Profil de charge standard (RLP Synergrid et équivalents).
     *
     * Moins fidèle à l'utilisateur, mais c'est ce que le FOURNISSEUR applique : pour
     * reproduire une facture, le profil standard est la bonne référence, pas la courbe
     * réelle.
     */
    case StandardProfile = 'standard_profile';

    /**
     * Moyenne arithmétique des cotations (« baseload »), chaque créneau au même poids.
     *
     * Repli de dernier recours, sans aucune information de forme de consommation. Il
     * sous-estime structurellement le coût d'un profil résidentiel, dont la
     * consommation se concentre aux heures les plus chères.
     */
    case Baseload = 'baseload';
}
