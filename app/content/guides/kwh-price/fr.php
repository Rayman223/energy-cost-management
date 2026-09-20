<?php

declare(strict_types=1);

/**
 * Guide « Comprendre le prix du kWh » (#85), version française.
 * Structure attendue : {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Comprendre le prix du kWh',
    'description' => 'Ce que vous payez vraiment pour un kilowattheure : énergie, réseau, taxes et TVA. De quoi lire sa facture et comparer deux offres sans se tromper.',
    'intro'       => 'Le prix affiché par un fournisseur ne représente qu\'une partie de ce que vous payez. Un kilowattheure facturé se compose de quatre briques distinctes, dont une seule relève réellement de la concurrence. Savoir les distinguer change la façon de comparer deux offres.',
    'sections'    => [
        [
            'h' => 'Les quatre composantes d\'un kWh',
            'p' => [
                'Sur une facture d\'électricité ou de gaz en Europe, le montant se décompose presque toujours de la même manière, même si les intitulés changent d\'un pays et d\'un fournisseur à l\'autre.',
            ],
            'ul' => [
                'L\'énergie : le coût d\'achat de l\'électricité ou du gaz, plus la marge du fournisseur. C\'est la seule partie sur laquelle jouer en changeant de contrat.',
                'Le réseau : l\'acheminement jusqu\'à chez vous, transport puis distribution. Le tarif est réglementé et dépend de votre zone géographique, pas de votre fournisseur.',
                'Les taxes et redevances : contributions diverses, souvent affectées à des politiques publiques (énergies renouvelables, cohésion sociale, éclairage communal).',
                'La TVA : appliquée sur l\'ensemble des trois postes précédents, y compris sur les autres taxes.',
            ],
        ],
        [
            'h' => 'Pourquoi changer de fournisseur ne divise pas la facture par deux',
            'p' => [
                'Selon les pays et les périodes, la part « énergie » représente grosso modo entre un tiers et la moitié du montant total. Le reste — réseau, taxes, TVA — est identique quel que soit le fournisseur choisi, pour une même adresse et une même consommation.',
                'Une promesse de remise de 20 % sur l\'énergie ne fait donc pas 20 % sur la facture, mais plutôt la moitié ou le tiers de cela. Ce n\'est pas négligeable, mais l\'ordre de grandeur mérite d\'être connu avant de signer.',
            ],
        ],
        [
            'h' => 'L\'abonnement, ce coût qui ne dépend pas de votre consommation',
            'p' => [
                'À côté du prix au kilowattheure, la plupart des contrats comportent une redevance fixe : abonnement du fournisseur, location ou relevé de compteur, terme fixe du réseau. Elle se paie que vous consommiez beaucoup ou très peu.',
                'C\'est ce qui rend les comparaisons trompeuses pour les petits consommateurs. Un contrat au kWh très bas mais à l\'abonnement élevé peut coûter plus cher qu\'un contrat au kWh moyen sans abonnement — d\'autant plus que votre consommation est faible.',
                'Pour comparer honnêtement, il faut ramener le tout à un coût complet : (prix du kWh × consommation annuelle) + abonnement annuel. C\'est ce calcul que fait cette application à partir de vos relevés réels.',
            ],
        ],
        [
            'h' => 'Heures pleines, heures creuses : un seul prix ou deux',
            'p' => [
                'Un compteur bihoraire enregistre séparément ce que vous consommez le jour et la nuit, facturés à deux prix différents. Le gain dépend entièrement de la part de votre consommation que vous parvenez à déplacer vers les heures creuses.',
                'Pour un foyer qui ne décale rien, un compteur bihoraire n\'apporte pas grand-chose, et peut même coûter davantage si le tarif de jour est plus élevé qu\'un tarif simple. Avec un ballon d\'eau chaude, un lave-vaisselle programmable ou une voiture électrique rechargée la nuit, l\'arbitrage change complètement.',
            ],
        ],
        [
            'h' => 'Comparer deux offres sans se tromper',
            'p' => [
                'Trois réflexes suffisent à éviter la plupart des mauvaises surprises.',
            ],
            'ul' => [
                'Partir de sa consommation annuelle réelle, en kWh, relevée sur ses propres factures — jamais d\'une estimation type.',
                'Additionner le terme variable et le terme fixe sur une année entière, TVA comprise, plutôt que de comparer deux prix au kWh.',
                'Vérifier la durée d\'engagement et les conditions de révision : un prix attractif la première année peut être révisé ensuite, parfois automatiquement.',
            ],
        ],
        [
            'h' => 'Et le gaz ?',
            'p' => [
                'La logique est la même, à une nuance près : votre compteur mesure des mètres cubes, alors que la facture est établie en kilowattheures. La conversion passe par un coefficient qui dépend du pouvoir calorifique du gaz distribué et de l\'altitude, et qui varie donc selon les périodes et les régions.',
                'C\'est pourquoi les statistiques de ce site publient le gaz en mètres cubes plutôt qu\'en kWh : convertir demanderait un coefficient propre à chaque contrat, et une moyenne le rendrait faux pour tout le monde.',
            ],
        ],
    ],
];
