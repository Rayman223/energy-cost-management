<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\EntsoePriceParser;
use DateTimeZone;
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
        // On force volontairement un fuseau NON-UTC pour prouver que le parser
        // stocke désormais en UTC indépendamment du fuseau PHP par défaut.
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

        // Stockage en UTC : 22:00Z reste 22:00 (indépendant du fuseau PHP).
        self::assertSame('2026-06-24 22:00', $prices[0]['period_start']->format('Y-m-d H:i'));
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
        self::assertSame('2026-06-24 22:15', $prices[1]['period_start']->format('Y-m-d H:i'));
        self::assertEqualsWithDelta(0.16, $prices[3]['price_eur_kwh'], 1e-9);
    }

    /**
     * Repli d'heure d'automne (nuit du 25 octobre 2026, CEST → CET) : la journée
     * compte 25 heures et les deux « 02:00 » murales belges sont deux instants UTC
     * distincts (00:00Z et 01:00Z), chacun avec son propre prix.
     *
     * Non-régression : ce comportement est acquis depuis le stockage UTC (#172) —
     * le test ne corrige rien, il verrouille l'invariant pour qu'un retour à une
     * clé en heure murale (qui écraserait la 2ᵉ heure, #173) casse la CI.
     */
    public function testAutumnDstFallbackKeepsBothRepeatedHours(): void
    {
        $prices = (new EntsoePriceParser())->parse(
            $this->hourlyDocument('2026-10-24T22:00Z', '2026-10-25T23:00Z', 25)
        );

        self::assertCount(25, $prices);

        // Les deux heures du repli : instants UTC distincts, prix distincts.
        self::assertSame('2026-10-25 00:00', $prices[2]['period_start']->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 01:00', $prices[3]['period_start']->format('Y-m-d H:i'));
        self::assertEqualsWithDelta(0.03, $prices[2]['price_eur_kwh'], 1e-9);
        self::assertEqualsWithDelta(0.04, $prices[3]['price_eur_kwh'], 1e-9);

        // … qui retombent bien sur la même heure murale locale : c'est cette
        // collision que l'ancienne dédup transformait en heure perdue.
        $brussels = new DateTimeZone('Europe/Brussels');
        self::assertSame('2026-10-25 02:00', $prices[2]['period_start']->setTimezone($brussels)->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 02:00', $prices[3]['period_start']->setTimezone($brussels)->format('Y-m-d H:i'));
    }

    /**
     * Avance d'heure de printemps (nuit du 29 mars 2026, CET → CEST) : la journée
     * compte 23 heures, la série UTC reste continue (pas de trou, pas de doublon).
     */
    public function testSpringDstForwardHasNoGap(): void
    {
        $prices = (new EntsoePriceParser())->parse(
            $this->hourlyDocument('2026-03-28T23:00Z', '2026-03-29T22:00Z', 23)
        );

        self::assertCount(23, $prices);
        self::assertSame('2026-03-28 23:00', $prices[0]['period_start']->format('Y-m-d H:i'));
        self::assertSame('2026-03-29 21:00', $prices[22]['period_start']->format('Y-m-d H:i'));

        // Progression strictement horaire d'un bout à l'autre de la transition.
        for ($i = 1; $i < 23; $i++) {
            self::assertSame(
                3600,
                $prices[$i]['period_start']->getTimestamp() - $prices[$i - 1]['period_start']->getTimestamp(),
                "Écart non horaire à la position $i"
            );
        }
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

    /**
     * Document A44 PT60M couvrant [$start, $end[ avec $count positions au prix
     * croissant (position N → 10×N €/MWh, soit 0,01×N €/kWh).
     */
    private function hourlyDocument(string $start, string $end, int $count): string
    {
        $points = '';
        for ($position = 1; $position <= $count; $position++) {
            $points .= sprintf(
                '<Point><position>%d</position><price.amount>%d.00</price.amount></Point>',
                $position,
                10 * $position
            );
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Publication_MarketDocument xmlns="urn:x"><TimeSeries><Period>'
            . sprintf('<timeInterval><start>%s</start><end>%s</end></timeInterval>', $start, $end)
            . '<resolution>PT60M</resolution>'
            . $points
            . '</Period></TimeSeries></Publication_MarketDocument>';
    }
}
