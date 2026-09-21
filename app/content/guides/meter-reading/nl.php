<?php

declare(strict_types=1);

/**
 * Gids "Uw meter en uw factuur lezen" (#85), Nederlandse versie.
 * Verwachte structuur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Uw meter en uw factuur lezen',
    'description' => 'Meterstand, verbruik, voorschotten en afrekening: hoe u uw meter opneemt, een factuur controleert en een fout opmerkt vóór ze duur wordt.',
    'intro'       => 'Een energiefactuur controleren gebeurt in twee stappen: de meter opnemen en vergelijken. Daarvoor moet u wel weten wat het scherm toont en wat de factuur daarmee doet. Hier leest u beide.',
    'sections'    => [
        [
            'h' => 'Meterstand en verbruik zijn niet hetzelfde',
            'p' => [
                'Een meter toont niet uw verbruik maar een meterstand: een teller die sinds de plaatsing alleen maar oploopt. Wat u over een periode verbruikte, is het verschil tussen twee standen.',
                'Daarom zegt één opname niets. Twee opnames met een maand ertussen zeggen alles: de aftrekking geeft de kilowattuur of kubieke meter van de periode, en delen door het aantal dagen geeft een daggemiddelde — het enige cijfer dat echt vergelijkbaar is van periode tot periode.',
                'Een wintermaand vergelijkt u niet met een zomermaand, maar met diezelfde maand een jaar eerder. Dat is de vergelijking die deze toepassing maakt zodra u een jaar geschiedenis hebt.',
            ],
        ],
        [
            'h' => 'Wat de meter toont',
            'p' => [
                'Afhankelijk van het model loopt het scherm door verschillende waarden. De nuttigste dragen vrij constante aanduidingen van land tot land.',
            ],
            'ul' => [
                'Eén enkele stand, als uw contract maar één tarief kent.',
                'Twee aparte standen bij een tweevoudig contract: één voor de piekuren, één voor de daluren. Beide moeten worden opgenomen.',
                'Een productiestand, als u zonnepanelen hebt: die telt wat u op het net injecteert, niet te verwarren met wat u verbruikt.',
                'Een momentaan vermogen in kW: nuttig om te begrijpen welk toestel doorweegt, maar zonder rechtstreeks verband met de factuur.',
            ],
        ],
        [
            'h' => 'Voorschotten en afrekening',
            'p' => [
                'De meeste contracten werken met voorschotten: u betaalt elke maand een geschat bedrag, en een jaarfactuur vereffent het verschil tussen wat u betaalde en wat u werkelijk verbruikte.',
                'Een voorschot is dus geen factuur maar een voorafbetaling. Een te laag voorschot bespaart niets, het schuift de rekening gewoon door naar de afrekening. Een te hoog voorschot leent uw leverancier renteloos geld.',
                'De juiste instelling controleert u één keer per jaar: overschrijdt uw afrekening stelselmatig één maand voorschot, in de ene of de andere richting, dan is het tijd om ze te laten aanpassen.',
            ],
        ],
        [
            'h' => 'Een afwijkende factuur herkennen',
            'p' => [
                'Enkele controles vangen de overgrote meerderheid van de fouten, en ze kosten maar een paar minuten.',
            ],
            'ul' => [
                'De beginstand op de factuur moet exact de eindstand van de vorige zijn. Een breuk daartussen wijst op een geschatte opname of een invoerfout.',
                'De vermelding "geschat" in plaats van "opgenomen": de leverancier heeft geëxtrapoleerd. Een echte opname doorgeven corrigeert de factuur.',
                'Een verbruik dat verdubbelt zonder gewijzigde gewoonte of seizoen verdient een inspectie: een waterlek, een boiler die blijft opwarmen, of een verkeerd afgelezen stand.',
                'Een tariefwijziging midden in de periode: de factuur moet die tonen als twee aparte lijnen, elk over haar deel van de periode.',
            ],
        ],
        [
            'h' => 'Hoe vaak opnemen?',
            'p' => [
                'Eén opname per maand volstaat ruimschoots om uw verbruik te volgen en afwijkingen te zien. De eerste dag van de maand is een handig ijkpunt, omdat de periodes zo vergelijkbaar worden zonder rekenwerk.',
                'Vaker opnemen levert alleen precisie op als u iets bepaalds zoekt: een energieslurpend toestel opsporen, het effect van een nieuwe verwarming meten, of een vermoed lek bevestigen. In die gevallen zegt een dagelijkse opname over twee weken meer dan een jaar maandelijkse opnames.',
            ],
        ],
        [
            'h' => 'Een spoor bijhouden',
            'p' => [
                'Het nut van een geschiedenis blijkt pas achteraf: zij laat toe te zeggen of een factuur afwijkt, of een contractwissel rendeerde, of de isolatie van vorig jaar iets opbracht.',
                'Het instrument doet er nauwelijks toe — een schriftje, een rekenblad, deze toepassing. Wat telt is regelmaat, en de exacte datum noteren bij de stand: zonder datum kunt u twee standen niet van elkaar aftrekken.',
            ],
        ],
    ],
];
