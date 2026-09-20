<?php

declare(strict_types=1);

/**
 * Gids "Vast tarief of dynamische prijs?" (#85), Nederlandse versie.
 * Verwachte structuur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Vast tarief of dynamische prijs?',
    'description' => 'Vaste prijs, variabele prijs, contracten gekoppeld aan de uurmarkt: wat elke formule werkelijk verandert, en voor wie ze past.',
    'intro'       => 'Kiezen tussen een gewaarborgde prijs en een prijs die de markt volgt, is geen gok op de energiekoersen: het is een afweging tussen voorspelbaarheid en blootstelling. Beide formules hebben hun logica, en de juiste hangt vooral af van uw vermogen om verbruik te verschuiven.',
    'sections'    => [
        [
            'h' => 'Drie formules, geen twee',
            'p' => [
                'Het aanbod op de markt valt uiteen in drie families, die u beter niet door elkaar haalt.',
            ],
            'ul' => [
                'De vaste prijs: het kWh-tarief ligt vast voor de hele looptijd van het contract. U weet wat u zult betalen, wat er op de markten ook gebeurt.',
                'De variabele prijs: het tarief wordt periodiek herzien, vaak per kwartaal, volgens een gepubliceerde index. U volgt de markt, maar met vertraging en zonder dagelijkse schokken.',
                'De dynamische prijs: het tarief volgt rechtstreeks de groothandelsmarkt, meestal uur per uur. U betaalt de prijs van elektriciteit op het moment dat u ze verbruikt.',
            ],
        ],
        [
            'h' => 'Hoe een dynamische prijs is opgebouwd',
            'p' => [
                'Geen enkele leverancier factureert de ruwe marktprijs. De contractuele formule voegt er bijna altijd twee termen aan toe, die u vóór ondertekening in de tariefvoorwaarden opzoekt.',
                'De eerste is een vermenigvuldigingscoëfficiënt op de marktprijs, die netverliezen en balanceringskosten dekt. De tweede is een vast bedrag per kilowattuur: de marge van de leverancier en diverse kosten.',
                'De betaalde prijs ziet er dus uit als: marktprijs × coëfficiënt, plus btw, plus een bedrag per kWh. Twee dynamische aanbiedingen op dezelfde markt kunnen daardoor merkbaar verschillen, en net op die twee termen wordt de vergelijking beslecht.',
            ],
        ],
        [
            'h' => 'Wie echt baat heeft bij dynamisch',
            'p' => [
                'Een uurprijs is alleen interessant als u een aanzienlijk deel van uw verbruik naar de goedkope uren kunt verschuiven. Zonder die verschuiving betaalt u gewoon het marktgemiddelde, met de volatiliteit erbovenop.',
                'De hefboom is heel concreet: een elektrische wagen die \'s nachts laadt, een aangestuurde warmtepomp of boiler, een thuisbatterij, grote huishoudtoestellen met timer. Een huishouden dat er meerdere combineert, kan een flink deel van zijn verbruik verschuiven.',
                'Omgekeerd verbruikt een huishouden waarvan het verbruik strikt de aanwezigheidsuren volgt — maaltijden, verlichting, televisie \'s avonds — juist wanneer vraag en prijzen op hun hoogst staan.',
            ],
        ],
        [
            'h' => 'Het risico, en wat het waard is',
            'p' => [
                'Een vaste prijs is niet gratis: de leverancier draagt het marktrisico in uw plaats en rekent dat aan als een premie die in het tarief zit. Over een lange periode is vast daarom gemiddeld vaak iets duurder — dat is de prijs van de gemoedsrust.',
                'De dynamische prijs schrapt die premie, maar verlegt het risico naar u. De recente energiecrisissen toonden wat dat betekent: uurprijzen die wekenlang enkele keren over de kop gingen, zonder contractueel plafond.',
                'De vraag is dus niet "welke formule wordt de goedkoopste", wat niemand weet, maar "brengt een factuur die drie maanden lang verdubbelt mij in moeilijkheden". Zo ja, dan is voorspelbaarheid haar premie waard.',
            ],
        ],
        [
            'h' => 'Beslissen op uw eigen cijfers',
            'p' => [
                'Een eerlijke simulatie heeft maar twee dingen nodig: uw werkelijke verbruik, liefst gespreid over de dag, en twaalf maanden marktprijzen van uw zone.',
                'Door de contractuele formule op die geschiedenis toe te passen, krijgt u wat u zou hebben betaald — niet wat u zult betalen, maar een orde van grootte die veel steviger is dan een onderbuikgevoel. Precies die vergelijking biedt deze toepassing op het dashboard wanneer dynamische prijzen zijn ingeschakeld.',
                'Nog één gewoonte: de gemeenschapsstatistieken tonen het aandeel huishoudens met een dynamisch contract per land. Dat is geen advies, maar het situeert de werkelijke praktijk, die enorm verschilt van markt tot markt.',
            ],
        ],
    ],
];
