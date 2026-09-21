<?php

declare(strict_types=1);

/**
 * Ratgeber „Den kWh-Preis verstehen“ (#85), deutsche Fassung.
 * Erwartete Struktur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Den kWh-Preis verstehen',
    'description' => 'Was Sie für eine Kilowattstunde tatsächlich zahlen: Energie, Netz, Abgaben und Mehrwertsteuer. Genug, um die eigene Rechnung zu lesen und zwei Angebote fair zu vergleichen.',
    'intro'       => 'Der Preis, den ein Anbieter bewirbt, ist nur ein Teil dessen, was Sie zahlen. Eine abgerechnete Kilowattstunde besteht aus vier getrennten Bausteinen, und nur einer davon steht wirklich im Wettbewerb. Sie auseinanderzuhalten verändert, wie man zwei Angebote vergleicht.',
    'sections'    => [
        [
            'h' => 'Die vier Bestandteile einer kWh',
            'p' => [
                'Auf einer europäischen Strom- oder Gasrechnung gliedert sich der Betrag fast immer gleich, auch wenn die Bezeichnungen sich von Land zu Land und von Anbieter zu Anbieter unterscheiden.',
            ],
            'ul' => [
                'Die Energie: der Beschaffungspreis für Strom oder Gas, zuzüglich der Marge des Anbieters. Nur an diesem Teil ändert ein Vertragswechsel etwas.',
                'Das Netz: die Lieferung bis zu Ihnen nach Hause, erst Übertragung, dann Verteilung. Das Entgelt ist reguliert und hängt von Ihrer Region ab, nicht von Ihrem Anbieter.',
                'Steuern und Abgaben: verschiedene Beiträge, oft für staatliche Aufgaben zweckgebunden (erneuerbare Energien, soziale Förderung, Straßenbeleuchtung).',
                'Die Mehrwertsteuer: auf die drei vorgenannten Posten erhoben, also auch auf die übrigen Abgaben.',
            ],
        ],
        [
            'h' => 'Warum ein Anbieterwechsel die Rechnung nicht halbiert',
            'p' => [
                'Je nach Land und Zeitraum macht der Energieanteil grob ein Drittel bis die Hälfte der Summe aus. Der Rest — Netz, Abgaben, Mehrwertsteuer — ist bei gleicher Adresse und gleichem Verbrauch unabhängig vom gewählten Anbieter identisch.',
                'Ein versprochener Nachlass von 20 % auf die Energie sind daher keine 20 % auf die Rechnung, sondern eher die Hälfte oder ein Drittel davon. Nicht nichts, aber die Größenordnung sollte man vor der Unterschrift kennen.',
            ],
        ],
        [
            'h' => 'Der Grundpreis, die Kosten unabhängig vom Verbrauch',
            'p' => [
                'Neben dem Preis je Kilowattstunde enthalten die meisten Verträge ein festes Entgelt: Grundpreis des Anbieters, Zählermiete oder Ablesung, fester Netzbestandteil. Es fällt an, ob Sie viel oder fast nichts verbrauchen.',
                'Genau das macht Vergleiche für kleine Verbraucher irreführend. Ein Vertrag mit sehr niedrigem kWh-Preis, aber hohem Grundpreis kann teurer sein als ein mittlerer kWh-Preis ohne Grundpreis — umso mehr bei geringem Verbrauch.',
                'Ehrlich vergleichen heißt, alles auf eine Vollkostenrechnung zu bringen: (kWh-Preis × Jahresverbrauch) + jährlicher Grundpreis. Genau diese Rechnung stellt diese Anwendung aus Ihren tatsächlichen Zählerständen auf.',
            ],
        ],
        [
            'h' => 'Hoch- und Niedertarif: ein Preis oder zwei',
            'p' => [
                'Ein Zweitarifzähler erfasst Tag- und Nachtverbrauch getrennt und rechnet sie zu zwei verschiedenen Preisen ab. Der Vorteil hängt vollständig davon ab, wie viel Verbrauch Sie tatsächlich in die Niedertarifzeiten verschieben.',
                'Für einen Haushalt, der nichts verschiebt, bringt ein Zweitarifzähler wenig und kann sogar teurer werden, wenn sein Tagtarif über einem Eintarif liegt. Mit Warmwasserspeicher, programmierbarer Spülmaschine oder einem nachts geladenen Elektroauto sieht die Abwägung völlig anders aus.',
            ],
        ],
        [
            'h' => 'Zwei Angebote vergleichen, ohne sich zu täuschen',
            'p' => [
                'Drei Gewohnheiten genügen, um die meisten bösen Überraschungen zu vermeiden.',
            ],
            'ul' => [
                'Vom tatsächlichen Jahresverbrauch in kWh ausgehen, abgelesen von den eigenen Rechnungen — nie von einer Standardschätzung.',
                'Verbrauchspreis und Grundpreis über ein volles Jahr addieren, inklusive Mehrwertsteuer, statt zwei kWh-Preise zu vergleichen.',
                'Laufzeit und Anpassungsklauseln prüfen: ein attraktiver Preis im ersten Jahr kann danach angepasst werden, mitunter automatisch.',
            ],
        ],
        [
            'h' => 'Und beim Gas?',
            'p' => [
                'Die Logik ist dieselbe, mit einer Besonderheit: Ihr Zähler misst Kubikmeter, abgerechnet wird aber in Kilowattstunden. Die Umrechnung nutzt einen Faktor, der vom Brennwert des gelieferten Gases und von der Höhenlage abhängt und deshalb je nach Zeitraum und Region schwankt.',
                'Deshalb veröffentlicht die Statistik dieser Seite Gas in Kubikmetern statt in kWh: eine Umrechnung bräuchte einen vertragsindividuellen Faktor, und ein Durchschnittswert machte die Zahl für alle falsch.',
            ],
        ],
    ],
];
