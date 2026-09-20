<?php

declare(strict_types=1);

/**
 * Ratgeber „Festtarif oder dynamischer Preis?“ (#85), deutsche Fassung.
 * Erwartete Struktur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Festtarif oder dynamischer Preis?',
    'description' => 'Festpreis, variabler Preis, an den Stundenmarkt gekoppelte Verträge: was jede Formel wirklich ändert und zu wem sie passt.',
    'intro'       => 'Die Wahl zwischen einem garantierten Preis und einem, der dem Markt folgt, ist keine Wette auf die Energiekurse: sie ist eine Abwägung zwischen Planbarkeit und Marktrisiko. Beide Formeln haben ihre Logik, und welche passt, hängt vor allem davon ab, wie weit Sie Verbrauch verschieben können.',
    'sections'    => [
        [
            'h' => 'Drei Formeln, nicht zwei',
            'p' => [
                'Die Angebote am Markt lassen sich in drei Familien einteilen, die man besser nicht verwechselt.',
            ],
            'ul' => [
                'Der Festpreis: der kWh-Preis ist für die gesamte Vertragslaufzeit garantiert. Sie wissen, was Sie zahlen werden, was auch immer an den Märkten geschieht.',
                'Der variable Preis: der Tarif wird regelmäßig angepasst, oft quartalsweise, anhand eines veröffentlichten Index. Sie folgen dem Markt, aber verzögert und ohne tägliche Sprünge.',
                'Der dynamische Preis: der Tarif folgt unmittelbar dem Großhandelsmarkt, meist stundenweise. Sie zahlen den Strompreis des Moments, in dem Sie verbrauchen.',
            ],
        ],
        [
            'h' => 'Wie ein dynamischer Preis entsteht',
            'p' => [
                'Kein Anbieter rechnet den reinen Marktpreis ab. Die Vertragsformel ergänzt fast immer zwei Terme, die man vor der Unterschrift in den Tarifbedingungen nachschlagen sollte.',
                'Der erste ist ein Faktor auf den Marktpreis, der Netzverluste und Ausgleichskosten abdeckt. Der zweite ist ein fester Betrag je Kilowattstunde: Marge des Anbieters und verschiedene Entgelte.',
                'Der gezahlte Preis sieht also so aus: Marktpreis × Faktor, plus Mehrwertsteuer, plus ein Betrag je kWh. Zwei dynamische Angebote auf demselben Markt können sich dadurch spürbar unterscheiden, und genau an diesen beiden Termen entscheidet sich der Vergleich.',
            ],
        ],
        [
            'h' => 'Wem dynamische Preise wirklich nützen',
            'p' => [
                'Ein Stundenpreis lohnt sich nur, wenn Sie einen nennenswerten Teil Ihres Verbrauchs in die günstigen Stunden verlegen können. Ohne diese Verlagerung zahlen Sie schlicht den Marktdurchschnitt, samt seiner Schwankungen.',
                'Der Hebel ist sehr konkret: ein nachts geladenes Elektroauto, eine gesteuerte Wärmepumpe oder ein Warmwasserspeicher, ein Hausspeicher, programmierte Großgeräte. Ein Haushalt, der mehreres davon kombiniert, kann einen erheblichen Teil seines Verbrauchs verschieben.',
                'Umgekehrt verbraucht ein Haushalt, dessen Verbrauch strikt den Anwesenheitszeiten folgt — Mahlzeiten, Licht, Fernsehen am Abend — genau dann, wenn Nachfrage und Preise am höchsten sind.',
            ],
        ],
        [
            'h' => 'Das Risiko und was es wert ist',
            'p' => [
                'Ein Festpreis ist nicht umsonst: der Anbieter trägt das Marktrisiko für Sie und verrechnet es als Prämie, die im Tarif steckt. Über lange Zeiträume ist fest deshalb im Mittel oft etwas teurer — das ist der Preis der Ruhe.',
                'Der dynamische Preis streicht diese Prämie, verlagert das Risiko aber auf Sie. Die jüngsten Energiekrisen haben gezeigt, was das heißt: über Wochen vervielfachte Stundenpreise, ohne vertragliche Obergrenze.',
                'Die Frage lautet also nicht „welche Formel wird die günstigste“, das weiß niemand, sondern „bringt mich eine Rechnung, die sich drei Monate lang verdoppelt, in Schwierigkeiten“. Wenn ja, ist Planbarkeit ihre Prämie wert.',
            ],
        ],
        [
            'h' => 'Mit den eigenen Zahlen entscheiden',
            'p' => [
                'Eine ehrliche Simulation braucht nur zweierlei: Ihren tatsächlichen Verbrauch, möglichst über den Tag verteilt, und zwölf Monate Marktpreisverlauf Ihrer Zone.',
                'Wendet man die Vertragsformel auf diesen Verlauf an, erhält man, was Sie gezahlt hätten — nicht, was Sie zahlen werden, aber eine weit belastbarere Größenordnung als ein Bauchgefühl. Genau diesen Vergleich bietet diese Anwendung im Dashboard, wenn dynamische Preise aktiviert sind.',
                'Eine letzte Gewohnheit: die Gemeinschaftsstatistik zeigt den Anteil der Haushalte mit dynamischem Vertrag je Land. Das ist kein Rat, aber es verortet die tatsächliche Praxis, die sich von Markt zu Markt enorm unterscheidet.',
            ],
        ],
    ],
];
