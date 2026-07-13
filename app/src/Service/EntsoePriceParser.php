<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SimpleXMLElement;

/**
 * Parse un document ENTSO-E « Publication_MarketDocument » (prix day-ahead A44).
 *
 * Logique pure (aucun I/O) → testable sans réseau, à la manière de MonthlyConsumptionInterpolator.
 * Normalise les prix en €/kWh HTVA dans la timezone locale de l'application.
 */
final class EntsoePriceParser
{
    /**
     * @return array<int, array{period_start: DateTimeImmutable, resolution_min: int, price_eur_kwh: float}>
     *
     * @throws RuntimeException XML invalide, vide, ou document d'acquittement (pas de données).
     */
    public function parse(string $body): array
    {
        $xml = $this->loadXml($body);

        // Réponse d'acquittement (pas de données / erreur métier) → message explicite.
        if ($xml->getName() === 'Acknowledgement_MarketDocument') {
            throw new RuntimeException(
                'ENTSO-E: ' . ($this->extractReason($xml) ?? 'aucune donnée pour la période demandée.')
            );
        }

        $localTz = new DateTimeZone(date_default_timezone_get());
        $prices  = [];

        foreach ($xml->TimeSeries as $series) {
            foreach ($series->Period as $period) {
                // Anti-collision DST scopée au Period : le repli d'heure d'automne
                // produit deux positions consécutives sur la même heure locale AU
                // SEIN d'un même Period. Réinitialisée à chaque Period pour ne PAS
                // supprimer des heures identiques provenant de séries/périodes qui
                // se chevauchent (la dédup inter-séries reste résolue à l'upsert DB).
                /** @var array<string, true> $seenLocalKeys */
                $seenLocalKeys = [];
                $resolutionMin = $this->resolutionToMinutes((string) $period->resolution);
                if ($resolutionMin === 0) {
                    continue;
                }

                $start = new DateTimeImmutable((string) $period->timeInterval->start);
                $end   = new DateTimeImmutable((string) $period->timeInterval->end);

                $stepSeconds = $resolutionMin * 60;
                $intervals   = intdiv($end->getTimestamp() - $start->getTimestamp(), $stepSeconds);
                if ($intervals <= 0) {
                    continue;
                }

                // Map position => prix (€/kWh). La courbe ENTSO-E peut omettre des
                // positions : le dernier prix connu reste valable (fill-forward).
                $byPosition = [];
                foreach ($period->Point as $point) {
                    $position              = (int) $point->position;
                    $byPosition[$position] = ((float) $point->{'price.amount'}) / 1000.0;
                }

                $lastPrice = null;
                for ($position = 1; $position <= $intervals; $position++) {
                    if (isset($byPosition[$position])) {
                        $lastPrice = $byPosition[$position];
                    }
                    if ($lastPrice === null) {
                        continue;
                    }

                    $periodStart = $start
                        ->modify(sprintf('+%d seconds', ($position - 1) * $stepSeconds))
                        ->setTimezone($localTz);

                    // Repli d'heure (DST automne) : deux instants UTC distincts
                    // retombent sur la même heure murale locale (ex. 02:00 CEST puis
                    // 02:00 CET), qui est la clé de stockage aval (sans offset). On
                    // conserve la PREMIÈRE position et on ignore la seconde plutôt
                    // que de l'écraser silencieusement. Limite connue : la 2ᵉ heure
                    // « répétée » n'est pas tarifée à part (stockage UTC = piste #130 B5).
                    $localKey = $resolutionMin . '@' . $periodStart->format('Y-m-d H:i');
                    if (isset($seenLocalKeys[$localKey])) {
                        continue;
                    }
                    $seenLocalKeys[$localKey] = true;

                    $prices[] = [
                        'period_start'   => $periodStart,
                        'resolution_min' => $resolutionMin,
                        'price_eur_kwh'  => round($lastPrice, 7),
                    ];
                }
            }
        }

        return $prices;
    }

    /**
     * Extrait le texte <Reason><text> d'un document d'acquittement / d'erreur, ou null.
     */
    public function errorReason(string $body): ?string
    {
        if (trim($body) === '' || !str_contains($body, '<')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($this->stripDefaultNamespace($body));
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $this->extractReason($xml);
    }

    private function loadXml(string $body): SimpleXMLElement
    {
        if (trim($body) === '') {
            throw new RuntimeException('ENTSO-E: réponse vide.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($this->stripDefaultNamespace($body));
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('ENTSO-E: XML invalide.');
        }

        return $xml;
    }

    /**
     * Supprime la déclaration de namespace par défaut pour naviguer sans préfixe.
     */
    private function stripDefaultNamespace(string $body): string
    {
        return preg_replace('/\sxmlns="[^"]*"/', '', $body, 1) ?? $body;
    }

    private function resolutionToMinutes(string $resolution): int
    {
        return match ($resolution) {
            'PT15M'         => 15,
            'PT30M'         => 30,
            'PT60M', 'PT1H' => 60,
            default         => 0,
        };
    }

    private function extractReason(SimpleXMLElement $xml): ?string
    {
        $text = $xml->Reason->text ?? null;
        if ($text === null) {
            return null;
        }

        $value = trim((string) $text);

        return $value !== '' ? $value : null;
    }
}
