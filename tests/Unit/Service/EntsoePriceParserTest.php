<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\EntsoePriceParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Parsing du document ENTSO-E A44 : conversion d'unités, fenêtre temporelle,
 * fill-forward des positions manquantes et gestion des acquittements.
 */
final class EntsoePriceParserTest extends TestCase
{
    private string $previousTz;

    protected function setUp(): void
    {
        // Le parser convertit l'UTC vers la timezone par défaut de l'app.
        $this->previousTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
    }

    public function testParsesHourlyPricesWithFillForwardAndUnitConversion(): void
    {
        // 24 intervalles PT60M ; positions 1, 2 et 4 fournies (3 manquante → report).
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Publication_MarketDocument xmlns="urn:iec62325.351:tc-62325-503:publicationdocument:7:0">
              <TimeSeries>
                <Period>
                  <timeInterval><start>2026-06-24T22:00Z</start><end>2026-06-25T22:00Z</end></timeInterval>
                  <resolution>PT60M</resolution>
                  <Point><position>1</position><price.amount>50.00</price.amount></Point>
                  <Point><position>2</position><price.amount>60.00</price.amount></Point>
                  <Point><position>4</position><price.amount>80.00</price.amount></Point>
                </Period>
              </TimeSeries>
            </Publication_MarketDocument>
            XML;

        $prices = (new EntsoePriceParser())->parse($xml);

        self::assertCount(24, $prices);

        // UTC 22:00Z → Bruxelles 00:00 (heure d'été, +2).
        self::assertSame('2026-06-25 00:00', $prices[0]['period_start']->format('Y-m-d H:i'));
        self::assertSame(60, $prices[0]['resolution_min']);

        // €/MWh → €/kWh (÷ 1000).
        self::assertEqualsWithDelta(0.05, $prices[0]['price_eur_kwh'], 1e-9);
        self::assertEqualsWithDelta(0.06, $prices[1]['price_eur_kwh'], 1e-9);
        // Position 3 manquante → report du dernier prix (0.06).
        self::assertEqualsWithDelta(0.06, $prices[2]['price_eur_kwh'], 1e-9);
        self::assertEqualsWithDelta(0.08, $prices[3]['price_eur_kwh'], 1e-9);
        // Au-delà : report de 0.08.
        self::assertEqualsWithDelta(0.08, $prices[23]['price_eur_kwh'], 1e-9);
    }

    public function testParses15MinResolution(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <Publication_MarketDocument xmlns="urn:x">
              <TimeSeries>
                <Period>
                  <timeInterval><start>2026-06-24T22:00Z</start><end>2026-06-24T23:00Z</end></timeInterval>
                  <resolution>PT15M</resolution>
                  <Point><position>1</position><price.amount>100.00</price.amount></Point>
                  <Point><position>2</position><price.amount>120.00</price.amount></Point>
                  <Point><position>3</position><price.amount>140.00</price.amount></Point>
                  <Point><position>4</position><price.amount>160.00</price.amount></Point>
                </Period>
              </TimeSeries>
            </Publication_MarketDocument>
            XML;

        $prices = (new EntsoePriceParser())->parse($xml);

        self::assertCount(4, $prices);
        self::assertSame(15, $prices[0]['resolution_min']);
        self::assertSame('2026-06-25 00:15', $prices[1]['period_start']->format('Y-m-d H:i'));
        self::assertEqualsWithDelta(0.16, $prices[3]['price_eur_kwh'], 1e-9);
    }

    public function testAcknowledgementThrowsWithReason(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <Acknowledgement_MarketDocument xmlns="urn:x">
              <Reason><code>999</code><text>No matching data found</text></Reason>
            </Acknowledgement_MarketDocument>
            XML;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No matching data found');

        (new EntsoePriceParser())->parse($xml);
    }

    public function testEmptyBodyThrows(): void
    {
        $this->expectException(RuntimeException::class);

        (new EntsoePriceParser())->parse('   ');
    }

    public function testErrorReasonExtractsText(): void
    {
        $xml = '<Acknowledgement_MarketDocument xmlns="urn:x"><Reason><text>Token invalid</text></Reason></Acknowledgement_MarketDocument>';

        self::assertSame('Token invalid', (new EntsoePriceParser())->errorReason($xml));
        self::assertNull((new EntsoePriceParser())->errorReason(''));
    }
}
