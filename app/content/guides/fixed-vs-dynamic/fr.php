<?php

declare(strict_types=1);

/**
 * Guide « Tarif fixe ou prix dynamique ? » (#85), version française.
 * Structure attendue : {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Tarif fixe ou prix dynamique ?',
    'description' => 'Prix fixe, prix variable, contrat indexé au marché horaire : ce que chaque formule change réellement, et à qui elle convient.',
    'intro'       => 'Le choix entre un prix garanti et un prix qui suit le marché n\'est pas un pari sur les cours de l\'énergie : c\'est un arbitrage entre prévisibilité et exposition. Les deux formules ont leur logique, et la bonne dépend surtout de votre capacité à décaler vos consommations.',
    'sections'    => [
        [
            'h' => 'Trois formules, pas deux',
            'p' => [
                'Les offres du marché se rangent en trois familles, qu\'il vaut mieux ne pas confondre.',
            ],
            'ul' => [
                'Le prix fixe : le tarif du kWh est garanti pour toute la durée du contrat. Vous savez ce que vous paierez, quoi qu\'il arrive sur les marchés.',
                'Le prix variable : le tarif est révisé périodiquement, souvent chaque trimestre, selon un indice publié. Vous suivez le marché, mais avec un décalage et sans à-coups quotidiens.',
                'Le prix dynamique : le tarif suit directement le marché de gros, généralement heure par heure. Vous payez le prix de l\'électricité au moment où vous la consommez.',
            ],
        ],
        [
            'h' => 'Comment se construit un prix dynamique',
            'p' => [
                'Aucun fournisseur ne facture le prix de marché brut. La formule contractuelle ajoute presque toujours deux termes, qu\'il faut chercher dans les conditions tarifaires avant de signer.',
                'Le premier est un coefficient multiplicateur appliqué au prix du marché, qui couvre les pertes réseau et les coûts d\'équilibrage. Le second est un montant fixe ajouté à chaque kilowattheure : marge du fournisseur et frais divers.',
                'Le prix payé ressemble donc à : prix de marché × coefficient, plus TVA, plus un montant par kWh. Deux offres dynamiques adossées au même marché peuvent ainsi différer sensiblement, et c\'est sur ces deux termes que se joue la comparaison.',
            ],
        ],
        [
            'h' => 'À qui profite réellement le dynamique',
            'p' => [
                'Un prix horaire n\'est intéressant que si vous pouvez déplacer une part significative de votre consommation vers les heures bon marché. Sans ce déplacement, vous payez simplement la moyenne du marché, avec sa volatilité en prime.',
                'Le levier est donc très concret : voiture électrique rechargée la nuit, pompe à chaleur ou ballon d\'eau chaude pilotés, batterie domestique, gros électroménager programmé. Un foyer qui cumule plusieurs de ces usages peut décaler une part importante de sa consommation.',
                'À l\'inverse, un foyer dont la consommation suit strictement les heures de présence — repas, éclairage, télévision en soirée — consomme précisément quand la demande et les prix sont au plus haut.',
            ],
        ],
        [
            'h' => 'Le risque, et ce qu\'il vaut',
            'p' => [
                'Un prix fixe n\'est pas gratuit : le fournisseur porte le risque de marché à votre place, et le facture sous forme d\'une prime incluse dans le tarif. Sur longue période, le fixe est donc souvent un peu plus cher en moyenne — c\'est le prix de la tranquillité.',
                'Le prix dynamique supprime cette prime, mais transfère le risque sur vous. Les crises énergétiques récentes ont montré ce que cela signifie : des prix horaires multipliés plusieurs fois pendant des semaines, sans plafond contractuel.',
                'La question n\'est donc pas « quelle formule sera la moins chère », que personne ne sait, mais « une facture qui double pendant trois mois me met-elle en difficulté ». Si oui, la prévisibilité vaut sa prime.',
            ],
        ],
        [
            'h' => 'Décider sur ses propres chiffres',
            'p' => [
                'Une simulation honnête n\'a besoin que de deux éléments : votre consommation réelle, si possible répartie dans la journée, et l\'historique des prix de marché de votre zone sur douze mois.',
                'En appliquant la formule contractuelle à cet historique, on obtient ce que vous auriez payé — pas ce que vous paierez, mais un ordre de grandeur bien plus solide qu\'une intuition. C\'est précisément la comparaison que cette application propose sur le tableau de bord quand les prix dynamiques sont activés.',
                'Un dernier réflexe : les statistiques communautaires montrent la part des foyers en contrat dynamique dans chaque pays. Ce n\'est pas un conseil, mais cela situe la pratique réelle, qui varie énormément d\'un marché à l\'autre.',
            ],
        ],
    ],
];
