<?php

declare(strict_types=1);

/**
 * Guide "Fixed tariff or dynamic pricing?" (#85), English version.
 * Expected structure: {@see \App\View\GuideContent}.
 */

return [
    'title'       => 'Fixed tariff or dynamic pricing?',
    'description' => 'Fixed price, variable price, contracts indexed to the hourly market: what each formula actually changes, and who it suits.',
    'intro'       => 'Choosing between a guaranteed price and one that follows the market is not a bet on energy prices: it is a trade-off between predictability and exposure. Both have their logic, and the right one depends above all on your ability to shift consumption.',
    'sections'    => [
        [
            'h' => 'Three formulas, not two',
            'p' => [
                'Market offers fall into three families, best not confused with one another.',
            ],
            'ul' => [
                'Fixed price: the kWh rate is guaranteed for the whole contract period. You know what you will pay, whatever happens on the markets.',
                'Variable price: the rate is revised periodically, often quarterly, against a published index. You follow the market, but with a lag and without daily jolts.',
                'Dynamic price: the rate tracks the wholesale market directly, usually hour by hour. You pay the price of electricity at the moment you consume it.',
            ],
        ],
        [
            'h' => 'How a dynamic price is built',
            'p' => [
                'No supplier bills the raw market price. The contractual formula almost always adds two terms, and they are worth looking up in the tariff conditions before signing.',
                'The first is a multiplier applied to the market price, covering grid losses and balancing costs. The second is a fixed amount added to every kilowatt-hour: supplier margin and assorted fees.',
                'The price you pay therefore looks like: market price × coefficient, plus VAT, plus an amount per kWh. Two dynamic offers tracking the same market can differ noticeably, and it is on those two terms that the comparison is settled.',
            ],
        ],
        [
            'h' => 'Who dynamic pricing actually benefits',
            'p' => [
                'An hourly price is only worthwhile if you can move a significant share of your consumption into the cheap hours. Without that shift you simply pay the market average, with its volatility thrown in.',
                'The lever is very concrete: an electric car charged overnight, a controlled heat pump or hot water tank, a home battery, large appliances on a timer. A household combining several of these can shift a substantial share of its consumption.',
                'Conversely, a household whose consumption strictly follows the hours people are home — meals, lighting, television in the evening — consumes precisely when demand and prices peak.',
            ],
        ],
        [
            'h' => 'The risk, and what it is worth',
            'p' => [
                'A fixed price is not free: the supplier carries the market risk on your behalf and charges for it through a premium baked into the rate. Over a long period, fixed pricing is therefore often slightly more expensive on average — that is the price of peace of mind.',
                'Dynamic pricing removes that premium but transfers the risk to you. Recent energy crises showed what that means: hourly prices multiplied several times over for weeks, with no contractual cap.',
                'The question is therefore not "which formula will be cheapest", which nobody knows, but "would a bill that doubles for three months put me in difficulty". If it would, predictability is worth its premium.',
            ],
        ],
        [
            'h' => 'Deciding on your own figures',
            'p' => [
                'An honest simulation needs only two things: your real consumption, ideally spread across the day, and twelve months of market price history for your zone.',
                'Applying the contractual formula to that history gives what you would have paid — not what you will pay, but an order of magnitude far sounder than a hunch. That is exactly the comparison this application offers on the dashboard when dynamic prices are enabled.',
                'One last habit: the community statistics show the share of households on a dynamic contract in each country. That is not advice, but it situates actual practice, which varies enormously from one market to the next.',
            ],
        ],
    ],
];
