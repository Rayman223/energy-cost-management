<?php

declare(strict_types=1);

/**
 * Guide "Understanding the price of a kWh" (#85), English version.
 * Expected structure: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Understanding the price of a kWh',
    'description' => 'What you actually pay for a kilowatt-hour: energy, network, taxes and VAT. Enough to read your bill and compare two offers without being misled.',
    'intro'       => 'The price a supplier advertises is only part of what you pay. A billed kilowatt-hour is made of four distinct blocks, and only one of them is genuinely open to competition. Telling them apart changes how you compare two offers.',
    'sections'    => [
        [
            'h' => 'The four components of a kWh',
            'p' => [
                'On a European electricity or gas bill, the amount almost always breaks down the same way, even though the wording differs from one country and one supplier to the next.',
            ],
            'ul' => [
                'Energy: the cost of buying the electricity or gas, plus the supplier margin. This is the only part you can act on by switching contracts.',
                'Network: delivery to your home, transmission then distribution. The tariff is regulated and depends on your geographic area, not on your supplier.',
                'Taxes and levies: various contributions, often earmarked for public policies (renewables, social support, street lighting).',
                'VAT: applied on top of all three items above, including on the other taxes.',
            ],
        ],
        [
            'h' => 'Why switching supplier does not halve your bill',
            'p' => [
                'Depending on the country and the period, the energy share represents roughly between a third and a half of the total. The rest — network, taxes, VAT — is identical whichever supplier you pick, for the same address and the same consumption.',
                'A promised 20% discount on energy is therefore not 20% off your bill, but closer to half or a third of that. Not nothing, but worth knowing before signing.',
            ],
        ],
        [
            'h' => 'The standing charge, the cost that ignores your consumption',
            'p' => [
                'Alongside the price per kilowatt-hour, most contracts carry a fixed charge: supplier subscription, meter rental or reading, fixed network term. You pay it whether you consume a lot or almost nothing.',
                'That is what makes comparisons misleading for small consumers. A contract with a very low kWh price but a high standing charge can cost more than an average kWh price with no standing charge — all the more so if your consumption is low.',
                'To compare honestly you have to reduce everything to a full cost: (kWh price × yearly consumption) + yearly standing charge. That is the calculation this application runs on your real meter readings.',
            ],
        ],
        [
            'h' => 'Peak and off-peak: one price or two',
            'p' => [
                'A two-rate meter records daytime and night-time consumption separately, billed at two different prices. The gain depends entirely on how much of your consumption you actually manage to shift into off-peak hours.',
                'For a household that shifts nothing, a two-rate meter brings little, and can even cost more if its daytime rate is higher than a single rate. With a hot water tank, a programmable dishwasher or an electric car charged overnight, the trade-off changes completely.',
            ],
        ],
        [
            'h' => 'Comparing two offers without being misled',
            'p' => [
                'Three habits are enough to avoid most bad surprises.',
            ],
            'ul' => [
                'Start from your real yearly consumption in kWh, read off your own bills — never from a standard estimate.',
                'Add the variable term and the fixed term over a full year, VAT included, rather than comparing two kWh prices.',
                'Check the commitment period and the revision terms: an attractive first-year price can be revised afterwards, sometimes automatically.',
            ],
        ],
        [
            'h' => 'What about gas?',
            'p' => [
                'The logic is the same, with one twist: your meter measures cubic metres, while the bill is drawn up in kilowatt-hours. The conversion uses a coefficient that depends on the calorific value of the gas delivered and on altitude, and therefore varies by period and region.',
                'This is why the statistics on this site publish gas in cubic metres rather than kWh: converting would require a coefficient specific to each contract, and an average one would make the figure wrong for everybody.',
            ],
        ],
    ],
];
