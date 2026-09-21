<?php

declare(strict_types=1);

/**
 * Guide « Lire son compteur et sa facture » (#85), version française.
 * Structure attendue : {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Lire son compteur et sa facture',
    'description' => 'Index, consommation, acomptes et régularisation : comment relever son compteur, vérifier une facture et repérer une erreur avant qu\'elle ne coûte cher.',
    'intro'       => 'Une facture d\'énergie se vérifie en deux gestes : relever son compteur et comparer. Encore faut-il savoir ce que montre l\'écran, et ce que la facture en fait. Voici de quoi lire les deux.',
    'sections'    => [
        [
            'h' => 'Index et consommation : deux choses différentes',
            'p' => [
                'Un compteur n\'affiche pas votre consommation, mais un index : un compteur cumulatif qui ne fait qu\'augmenter depuis l\'installation. Ce que vous consommez sur une période, c\'est la différence entre deux index.',
                'C\'est pourquoi un relevé isolé ne dit rien. Deux relevés espacés d\'un mois disent tout : la soustraction donne les kilowattheures ou les mètres cubes de la période, et la division par le nombre de jours donne une moyenne journalière, seule grandeur réellement comparable d\'une période à l\'autre.',
                'Un mois d\'hiver ne se compare pas à un mois d\'été, mais à ce même mois l\'année précédente. C\'est la comparaison que fait cette application dès que vous avez un an d\'historique.',
            ],
        ],
        [
            'h' => 'Ce qu\'affiche le compteur',
            'p' => [
                'Selon le modèle, l\'écran fait défiler plusieurs valeurs. Les plus utiles portent des repères assez stables d\'un pays à l\'autre.',
            ],
            'ul' => [
                'Un index unique, si votre contrat n\'a qu\'un seul tarif.',
                'Deux index séparés pour un contrat bihoraire : l\'un pour les heures pleines, l\'autre pour les heures creuses. Les deux doivent être relevés.',
                'Un index de production, si vous avez des panneaux solaires : il compte ce que vous injectez sur le réseau, à ne pas confondre avec ce que vous consommez.',
                'Une puissance instantanée, en kW : utile pour comprendre quel appareil pèse, mais sans rapport direct avec la facture.',
            ],
        ],
        [
            'h' => 'Acomptes et régularisation',
            'p' => [
                'La plupart des contrats fonctionnent par acomptes : vous payez chaque mois un montant estimé, et une facture annuelle solde la différence entre ce que vous avez payé et ce que vous avez réellement consommé.',
                'L\'acompte n\'est donc pas une facture : c\'est une avance. Un acompte trop bas ne fait pas faire d\'économie, il repousse simplement la note à la régularisation. Un acompte trop haut prête de l\'argent au fournisseur sans intérêt.',
                'Le bon réglage se vérifie une fois par an : si votre régularisation dépasse régulièrement un mois d\'acompte, dans un sens ou dans l\'autre, il est temps de la faire ajuster.',
            ],
        ],
        [
            'h' => 'Repérer une facture anormale',
            'p' => [
                'Quelques vérifications suffisent à attraper la grande majorité des erreurs, et elles se font en quelques minutes.',
            ],
            'ul' => [
                'L\'index de départ de la facture doit être exactement l\'index d\'arrivée de la précédente. Une rupture entre les deux signale un relevé estimé ou une erreur de saisie.',
                'La mention « estimé » plutôt que « relevé » : le fournisseur a extrapolé. Transmettre un relevé réel corrige la facture.',
                'Une consommation qui double sans changement d\'habitude ni de saison mérite une inspection : fuite d\'eau, chauffe-eau bloqué en chauffe, ou index mal lu.',
                'Un changement de tarif en cours de période : la facture doit le refléter par deux lignes distinctes, chacune sur sa fraction de période.',
            ],
        ],
        [
            'h' => 'À quelle fréquence relever ?',
            'p' => [
                'Un relevé par mois suffit largement pour suivre sa consommation et détecter une dérive. Le premier jour du mois est un repère commode, parce qu\'il rend les périodes comparables sans calcul.',
                'Relever plus souvent n\'apporte de la précision que si vous cherchez quelque chose de précis : identifier un appareil énergivore, mesurer l\'effet d\'un changement de chauffage, ou vérifier une suspicion de fuite. Dans ces cas, un relevé quotidien sur deux semaines en dit plus qu\'un an de relevés mensuels.',
            ],
        ],
        [
            'h' => 'Garder une trace',
            'p' => [
                'L\'intérêt d\'un historique n\'apparaît qu\'après coup : c\'est lui qui permet de dire si une facture est anormale, si un changement de contrat a été rentable, ou si l\'isolation posée l\'an dernier a servi.',
                'Peu importe l\'outil — un carnet, un tableur, cette application. Ce qui compte, c\'est la régularité et le fait de noter la date exacte avec l\'index : sans date, deux relevés ne se soustraient pas.',
            ],
        ],
    ],
];
