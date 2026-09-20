<?php

declare(strict_types=1);

/**
 * Gids "De prijs van een kWh begrijpen" (#85), Nederlandse versie.
 * Verwachte structuur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'De prijs van een kWh begrijpen',
    'description' => 'Wat u werkelijk betaalt voor een kilowattuur: energie, netwerk, heffingen en btw. Genoeg om uw factuur te lezen en twee aanbiedingen eerlijk te vergelijken.',
    'intro'       => 'De prijs die een leverancier afficheert, is maar een deel van wat u betaalt. Een gefactureerd kilowattuur bestaat uit vier afzonderlijke blokken, en slechts één daarvan staat echt open voor concurrentie. Ze uit elkaar houden verandert hoe u twee aanbiedingen vergelijkt.',
    'sections'    => [
        [
            'h' => 'De vier onderdelen van een kWh',
            'p' => [
                'Op een Europese elektriciteits- of gasfactuur valt het bedrag bijna altijd op dezelfde manier uiteen, ook al verschillen de benamingen per land en per leverancier.',
            ],
            'ul' => [
                'De energie: de aankoopkost van de elektriciteit of het gas, plus de marge van de leverancier. Dit is het enige deel waarop u kunt spelen door van contract te veranderen.',
                'Het netwerk: het transport tot bij u thuis, eerst transmissie en dan distributie. Het tarief is gereguleerd en hangt af van uw regio, niet van uw leverancier.',
                'Heffingen en toeslagen: uiteenlopende bijdragen, vaak bestemd voor overheidsbeleid (hernieuwbare energie, sociale steun, openbare verlichting).',
                'De btw: berekend op het geheel van de drie voorgaande posten, dus ook op de andere heffingen.',
            ],
        ],
        [
            'h' => 'Waarom van leverancier veranderen uw factuur niet halveert',
            'p' => [
                'Afhankelijk van het land en de periode vertegenwoordigt het energiedeel ruwweg een derde tot de helft van het totaal. De rest — netwerk, heffingen, btw — is identiek welke leverancier u ook kiest, bij hetzelfde adres en hetzelfde verbruik.',
                'Een beloofde korting van 20 % op de energie is dus geen 20 % op de factuur, maar eerder de helft of een derde daarvan. Niet niets, maar het is goed dat te weten vóór u tekent.',
            ],
        ],
        [
            'h' => 'Het vastrecht, de kost die niets met uw verbruik te maken heeft',
            'p' => [
                'Naast de prijs per kilowattuur kennen de meeste contracten een vaste vergoeding: abonnement van de leverancier, huur of opname van de meter, vaste netwerkterm. U betaalt die of u nu veel of bijna niets verbruikt.',
                'Dat maakt vergelijkingen misleidend voor kleine verbruikers. Een contract met een erg lage kWh-prijs maar een hoog vastrecht kan duurder uitvallen dan een gemiddelde kWh-prijs zonder vastrecht — zeker bij een laag verbruik.',
                'Eerlijk vergelijken betekent alles herleiden tot een volledige kost: (kWh-prijs × jaarverbruik) + jaarlijks vastrecht. Precies die berekening maakt deze toepassing op basis van uw echte meterstanden.',
            ],
        ],
        [
            'h' => 'Piek en dal: één prijs of twee',
            'p' => [
                'Een tweevoudige meter registreert dag- en nachtverbruik apart, gefactureerd tegen twee verschillende prijzen. De winst hangt volledig af van het deel van uw verbruik dat u werkelijk naar de daluren verschuift.',
                'Voor een huishouden dat niets verschuift, levert een tweevoudige meter weinig op, en hij kan zelfs duurder uitvallen als het dagtarief hoger ligt dan een enkelvoudig tarief. Met een boiler, een programmeerbare vaatwasser of een elektrische wagen die \'s nachts laadt, ziet de afweging er helemaal anders uit.',
            ],
        ],
        [
            'h' => 'Twee aanbiedingen vergelijken zonder u te vergissen',
            'p' => [
                'Drie gewoontes volstaan om de meeste tegenvallers te vermijden.',
            ],
            'ul' => [
                'Vertrek van uw werkelijke jaarverbruik in kWh, afgelezen van uw eigen facturen — nooit van een standaardschatting.',
                'Tel de variabele term en de vaste term op over een volledig jaar, btw inbegrepen, in plaats van twee kWh-prijzen te vergelijken.',
                'Controleer de looptijd en de herzieningsvoorwaarden: een aantrekkelijke prijs in het eerste jaar kan nadien worden herzien, soms automatisch.',
            ],
        ],
        [
            'h' => 'En gas?',
            'p' => [
                'De logica is dezelfde, met één verschil: uw meter meet kubieke meter, terwijl de factuur in kilowattuur is opgesteld. De omrekening gebruikt een coëfficiënt die afhangt van de verbrandingswaarde van het geleverde gas en van de hoogteligging, en die dus verschilt per periode en per regio.',
                'Daarom publiceert de statistiek op deze site gas in kubieke meter en niet in kWh: omrekenen zou een coëfficiënt per contract vergen, en één gemiddelde zou het cijfer voor iedereen verkeerd maken.',
            ],
        ],
    ],
];
