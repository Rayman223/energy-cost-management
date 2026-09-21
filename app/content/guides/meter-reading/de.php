<?php

declare(strict_types=1);

/**
 * Ratgeber „Zähler und Rechnung lesen“ (#85), deutsche Fassung.
 * Erwartete Struktur: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Zähler und Rechnung lesen',
    'description' => 'Zählerstand, Verbrauch, Abschläge und Jahresabrechnung: wie Sie ablesen, eine Rechnung prüfen und einen Fehler bemerken, bevor er teuer wird.',
    'intro'       => 'Eine Energierechnung prüft man in zwei Schritten: ablesen und vergleichen. Dafür muss man allerdings wissen, was das Display zeigt und was die Rechnung damit macht. Hier steht beides.',
    'sections'    => [
        [
            'h' => 'Zählerstand und Verbrauch sind nicht dasselbe',
            'p' => [
                'Ein Zähler zeigt nicht Ihren Verbrauch, sondern einen Zählerstand: einen kumulativen Zähler, der seit dem Einbau nur steigt. Was Sie in einem Zeitraum verbraucht haben, ist die Differenz zweier Stände.',
                'Deshalb sagt eine einzelne Ablesung nichts. Zwei Ablesungen im Abstand eines Monats sagen alles: die Subtraktion ergibt die Kilowattstunden oder Kubikmeter des Zeitraums, und die Division durch die Zahl der Tage einen Tagesdurchschnitt — die einzige Größe, die sich von Zeitraum zu Zeitraum wirklich vergleichen lässt.',
                'Ein Wintermonat lässt sich nicht mit einem Sommermonat vergleichen, wohl aber mit demselben Monat des Vorjahres. Genau diesen Vergleich zieht diese Anwendung, sobald Sie ein Jahr Historie haben.',
            ],
        ],
        [
            'h' => 'Was der Zähler anzeigt',
            'p' => [
                'Je nach Modell blättert das Display durch mehrere Werte. Die nützlichen tragen länderübergreifend recht einheitliche Kennungen.',
            ],
            'ul' => [
                'Ein einzelner Zählerstand, wenn Ihr Vertrag nur einen Tarif hat.',
                'Zwei getrennte Stände bei einem Zweitarifvertrag: einer für die Hochtarifzeit, einer für die Niedertarifzeit. Beide müssen abgelesen werden.',
                'Ein Einspeisestand, wenn Sie eine Photovoltaikanlage haben: er zählt, was Sie ins Netz abgeben — nicht zu verwechseln mit dem, was Sie verbrauchen.',
                'Eine Momentanleistung in kW: nützlich, um zu erkennen, welches Gerät ins Gewicht fällt, aber ohne direkten Bezug zur Rechnung.',
            ],
        ],
        [
            'h' => 'Abschläge und Jahresabrechnung',
            'p' => [
                'Die meisten Verträge arbeiten mit Abschlägen: Sie zahlen monatlich einen geschätzten Betrag, und eine Jahresrechnung gleicht die Differenz zwischen Gezahltem und tatsächlichem Verbrauch aus.',
                'Ein Abschlag ist also keine Rechnung, sondern eine Vorauszahlung. Ein zu niedriger Abschlag spart nichts, er verschiebt den Betrag nur auf die Abrechnung. Ein zu hoher leiht dem Anbieter zinslos Geld.',
                'Die richtige Höhe prüft man einmal im Jahr: übersteigt Ihre Abrechnung regelmäßig einen Monatsabschlag, in die eine oder andere Richtung, sollten Sie sie anpassen lassen.',
            ],
        ],
        [
            'h' => 'Eine auffällige Rechnung erkennen',
            'p' => [
                'Wenige Prüfungen fangen die allermeisten Fehler ab, und sie dauern Minuten.',
            ],
            'ul' => [
                'Der Anfangsstand der Rechnung muss exakt der Endstand der vorigen sein. Ein Sprung dazwischen deutet auf eine Schätzung oder einen Eingabefehler hin.',
                'Der Vermerk „geschätzt“ statt „abgelesen“: der Anbieter hat hochgerechnet. Eine echte Ablesung nachzureichen korrigiert die Rechnung.',
                'Ein Verbrauch, der sich ohne geänderte Gewohnheit und ohne Jahreszeitwechsel verdoppelt, gehört geprüft: ein Wasserleck, ein durchheizender Boiler oder ein falsch abgelesener Stand.',
                'Ein Tarifwechsel mitten im Zeitraum: die Rechnung muss ihn als zwei getrennte Positionen ausweisen, jede über ihren Teil des Zeitraums.',
            ],
        ],
        [
            'h' => 'Wie oft ablesen?',
            'p' => [
                'Eine Ablesung im Monat genügt völlig, um den Verbrauch zu verfolgen und eine Abweichung zu bemerken. Der Monatserste ist ein praktischer Fixpunkt, weil die Zeiträume dann ohne Rechnerei vergleichbar sind.',
                'Häufiger abzulesen bringt nur Genauigkeit, wenn Sie etwas Bestimmtes suchen: ein stromhungriges Gerät finden, die Wirkung einer neuen Heizung messen oder einen vermuteten Leck bestätigen. Dann sagt eine tägliche Ablesung über zwei Wochen mehr als ein Jahr monatlicher Werte.',
            ],
        ],
        [
            'h' => 'Aufzeichnungen führen',
            'p' => [
                'Der Wert einer Historie zeigt sich erst im Nachhinein: sie erlaubt die Aussage, ob eine Rechnung auffällig ist, ob sich ein Vertragswechsel gelohnt hat oder ob die Dämmung vom Vorjahr etwas gebracht hat.',
                'Das Werkzeug ist fast gleichgültig — ein Heft, eine Tabelle, diese Anwendung. Es zählen die Regelmäßigkeit und das genaue Datum neben dem Stand: ohne Datum lassen sich zwei Stände nicht voneinander abziehen.',
            ],
        ],
    ],
];
