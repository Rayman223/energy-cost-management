<?php

declare(strict_types=1);

/**
 * Guide "Reading your meter and your bill" (#85), English version.
 * Expected structure: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Reading your meter and your bill',
    'description' => 'Readings, consumption, instalments and the annual settlement: how to read your meter, check a bill, and catch an error before it gets expensive.',
    'intro'       => 'Checking an energy bill takes two steps: read your meter, then compare. That still requires knowing what the display shows, and what the bill does with it. Here is how to read both.',
    'sections'    => [
        [
            'h' => 'Meter reading and consumption are not the same thing',
            'p' => [
                'A meter does not display your consumption but a reading: a cumulative counter that has only gone up since installation. What you consumed over a period is the difference between two readings.',
                'That is why a single reading says nothing. Two readings a month apart say everything: the subtraction gives the kilowatt-hours or cubic metres for the period, and dividing by the number of days gives a daily average — the only figure that is genuinely comparable from one period to the next.',
                'A winter month is not comparable to a summer month, but to the same month a year earlier. That is the comparison this application makes as soon as you have a year of history.',
            ],
        ],
        [
            'h' => 'What the meter shows',
            'p' => [
                'Depending on the model, the display cycles through several values. The useful ones carry fairly consistent labels across countries.',
            ],
            'ul' => [
                'A single reading, if your contract has one rate only.',
                'Two separate readings for a two-rate contract: one for peak hours, one for off-peak. Both have to be recorded.',
                'A production reading, if you have solar panels: it counts what you feed into the grid, not to be confused with what you consume.',
                'An instantaneous power figure, in kW: useful to understand which appliance weighs, but with no direct bearing on the bill.',
            ],
        ],
        [
            'h' => 'Instalments and the annual settlement',
            'p' => [
                'Most contracts work on instalments: you pay an estimated amount every month, and a yearly bill settles the difference between what you paid and what you actually consumed.',
                'An instalment is therefore not a bill, it is an advance. An instalment set too low saves nothing, it merely pushes the amount to the settlement. One set too high lends money to your supplier free of charge.',
                'The right level is checked once a year: if your settlement regularly exceeds one month of instalments, either way, it is time to have it adjusted.',
            ],
        ],
        [
            'h' => 'Spotting an abnormal bill',
            'p' => [
                'A few checks catch the vast majority of errors, and they take minutes.',
            ],
            'ul' => [
                'The opening reading on the bill must be exactly the closing reading of the previous one. A gap between the two points to an estimated reading or a data-entry error.',
                'The word "estimated" rather than "actual": the supplier extrapolated. Submitting a real reading corrects the bill.',
                'Consumption that doubles with no change of habit or season deserves a look: a water leak, a water heater stuck on, or a misread meter.',
                'A tariff change mid-period: the bill must show it as two separate lines, each over its own fraction of the period.',
            ],
        ],
        [
            'h' => 'How often should you read the meter?',
            'p' => [
                'One reading a month is plenty to track consumption and detect drift. The first day of the month is a convenient marker, because it makes periods comparable without any arithmetic.',
                'Reading more often only adds precision if you are looking for something specific: identifying a power-hungry appliance, measuring the effect of a new heating system, or confirming a suspected leak. In those cases a daily reading over two weeks tells you more than a year of monthly ones.',
            ],
        ],
        [
            'h' => 'Keeping a record',
            'p' => [
                'The value of a history only appears afterwards: it is what lets you say whether a bill is abnormal, whether switching contracts paid off, or whether last year\'s insulation work did anything.',
                'The tool hardly matters — a notebook, a spreadsheet, this application. What matters is regularity, and writing the exact date next to the reading: without a date, two readings cannot be subtracted.',
            ],
        ],
    ],
];
