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
 * Le profil contractuel est prioritaire ; courbe réelle et baseload sont des replis estimés.
 */
enum LoadWeighting: string
{
    /**
     * Courbe réelle mesurée à un pas compatible avec les cotations (15 ou 60 min).
     *
     * Estimation sans profil contractuel : sa consommation pondère les prix. La
     * formule étant affine, ce cas redonne le total d'une facturation créneau par
     * créneau, à cotations et résolution identiques. Une égalité entre le coût
     * mensuel estimé et le comparatif dynamique reste donc possible.
     */
    case ActualLoad = 'actual_load';

    /**
     * Profil de charge standard (RLP Synergrid et équivalents).
     *
     * Référence prioritaire : c'est ce que le FOURNISSEUR applique. Pour
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
