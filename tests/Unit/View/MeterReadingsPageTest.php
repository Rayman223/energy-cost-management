<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Domain\Battery;
use App\Domain\Meter;
use App\Support\Dates;
use App\View\ViewFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Sélecteur de batterie de la page de saisie (#68).
 *
 * Le test porte sur le RENDU parce que c'est lui qui porte l'état : rien dans le
 * JS ne sait qu'une batterie est hors service, il ne lit que `data-closed` /
 * `data-closed-now` sur l'option sélectionnée. Ces deux attributs manquants, la
 * saisie repart au serveur et le refus revient en 422, avec le message anglais
 * brut de `ClosedMeterException` affiché tel quel — le symptôme de #68.
 *
 * Les deux attributs répondent à deux questions distinctes, et c'est le cœur de
 * ce qui est vérifié ici :
 *   - `data-closed` porte la DATE, dès qu'elle existe, même à venir : une fin de
 *     service programmée refuse déjà les relevés datés d'après elle ;
 *   - `data-closed-now` dit que la fin de service a PRIS EFFET, ce qui seul
 *     autorise à en parler au présent (suffixe de l'option, message informatif).
 *
 * Même découpage que pour les compteurs fermés, vérifié côté /meters par
 * {@see MetersPageTest}.
 */
final class MeterReadingsPageTest extends TestCase
{
    private const TZ = 'Europe/Brussels';

    /** Date décalée de $days par rapport au jour courant DU FOYER. */
    private function day(int $days): DateTimeImmutable
    {
        return Dates::todayIn(self::TZ)->modify(sprintf('%+d days', $days));
    }

    private function battery(?DateTimeImmutable $decommissionedOn, string $model = 'Powerwall'): Battery
    {
        return new Battery(
            id: 1,
            brand: 'Tesla',
            model: $model,
            capacityKwh: 13.5,
            commissionedOn: $this->day(-400),
            decommissionedOn: $decommissionedOn,
        );
    }

    private function meter(?DateTimeImmutable $closedOn, string $label = 'Cuisine'): Meter
    {
        return new Meter(id: 1, energyType: 'electricity', label: $label, closedOn: $closedOn);
    }

    /**
     * @param list<Battery>                $batteries
     * @param array<string, list<Meter>>   $metersByEnergy
     */
    private function render(array $batteries, string $locale = 'fr', array $metersByEnergy = []): string
    {
        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', $locale);

        return $view->render('meter_readings', [
            'oidcEnabled'    => false,
            'discordUrl'     => null,
            'donateUrl'      => null,
            'adsenseClient'  => null,
            'isAdmin'        => false,
            'dbError'        => null,
            'gasLatest'      => null,
            'waterLatest'    => null,
            'batteries'      => $batteries,
            'metersByEnergy' => $metersByEnergy,
            'available'      => ['fr', 'en', 'nl', 'de'],
            'timezone'       => self::TZ,
            'clockTimezone'  => self::TZ,
        ]);
    }

    /** Le fragment `<option …>` de la batterie, isolé du reste de la page. */
    private function batteryOption(string $html): string
    {
        self::assertSame(1, preg_match('#<select id="battery-target".*?</select>#s', $html, $select));
        self::assertSame(1, preg_match('#<option .*?</option>#s', $select[0], $option));

        return $option[0];
    }

    /** Le fragment `<option …>` du compteur électrique, isolé du reste de la page. */
    private function meterOption(string $html): string
    {
        self::assertSame(1, preg_match('#<select id="electricity-meter".*?</select>#s', $html, $select));
        self::assertSame(1, preg_match('#<option .*?</option>#s', $select[0], $option));

        return $option[0];
    }

    public function testDecommissionedBatteryCarriesItsDateAndIsFlaggedAsAlreadyOutOfService(): void
    {
        $option = $this->batteryOption($this->render([$this->battery($this->day(-3))]));

        self::assertStringContainsString('data-closed="' . $this->day(-3)->format('Y-m-d') . '"', $option);
        self::assertStringContainsString('data-closed-now="1"', $option);
    }

    /**
     * Fin de service PROGRAMMÉE : le serveur refuse déjà un relevé daté d'après
     * elle, donc le JS doit pouvoir comparer la date — mais la batterie tourne
     * encore, et la dire hors service aujourd'hui serait faux.
     */
    public function testScheduledEndOfServiceCarriesTheDateWithoutClaimingItTookEffect(): void
    {
        $option = $this->batteryOption($this->render([$this->battery($this->day(10))]));

        self::assertStringContainsString('data-closed="' . $this->day(10)->format('Y-m-d') . '"', $option);
        self::assertStringNotContainsString('data-closed-now', $option);
        self::assertStringNotContainsString('fin de service le', $option);
    }

    public function testBatteryInServiceCarriesNoClosureAttributeAtAll(): void
    {
        $option = $this->batteryOption($this->render([$this->battery(null)]));

        self::assertStringNotContainsString('data-closed', $option);
    }

    /**
     * La date est DANS le libellé, et pas seulement dans un attribut : le
     * sélecteur est masqué quand le parc ne compte qu'une batterie, mais dès
     * qu'il y en a deux, c'est ce suffixe qui distingue celle qu'on peut encore
     * alimenter de celle qui est arrêtée.
     */
    public function testDecommissionedBatteryIsSuffixedInTheReadersLanguage(): void
    {
        $decommissionedOn = $this->day(-3)->format('Y-m-d');

        self::assertStringContainsString(
            'fin de service le ' . $decommissionedOn,
            $this->batteryOption($this->render([$this->battery($this->day(-3))])),
        );
        self::assertStringContainsString(
            'out of service on ' . $decommissionedOn,
            $this->batteryOption($this->render([$this->battery($this->day(-3))], locale: 'en')),
        );
    }

    /**
     * Les deux messages du verrou voyagent par #meter-data : `tr()` retombe
     * silencieusement sur son libellé anglais de repli si la clé manque — c'est
     * exactement l'anglais brut que #68 cherche à faire disparaître.
     */
    public function testBlockingMessagesTravelTranslatedInTheDataBlock(): void
    {
        $html = $this->render([$this->battery($this->day(-3))]);

        self::assertSame(1, preg_match('#<script type="application/json" id="meter-data">(.*?)</script>#s', $html, $m));
        /** @var array{i18n: array<string, string>} $data */
        $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('hors service', $data['i18n']['batteryDecommissioned']);
        self::assertStringContainsString('{date}', $data['i18n']['batteryDecommissionedOn']);
        // Vocabulaire des compteurs : transposé à la batterie, il parlerait d'un
        // « compteur fermé » là où il n'y a qu'une batterie déposée.
        self::assertStringNotContainsString('compteur', $data['i18n']['batteryDecommissioned']);
    }

    /** Sans batterie déclarée, la section entière — donc le verrou — n'existe pas. */
    public function testSectionIsAbsentWithoutAnyBattery(): void
    {
        self::assertStringNotContainsString('battery-target', $this->render([]));
    }

    /**
     * Même découpage pour le sélecteur de COMPTEUR (#69) : un compteur déjà fermé
     * se dit au passé et porte `data-closed-now`.
     */
    public function testClosedMeterIsSuffixedInThePastAndFlaggedAsAlreadyClosed(): void
    {
        $closedOn = $this->day(-3);

        $option = $this->meterOption($this->render([], metersByEnergy: ['electricity' => [$this->meter($closedOn)]]));

        self::assertStringContainsString('fermé le ' . $closedOn->format('Y-m-d'), $option);
        self::assertStringContainsString('data-closed="' . $closedOn->format('Y-m-d') . '"', $option);
        self::assertStringContainsString('data-closed-now="1"', $option);
    }

    /**
     * Fermeture PROGRAMMÉE : le compteur accepte encore des relevés, donc rien ne
     * doit le dire fermé — mais taire la date laissait le choisir sans savoir
     * qu'il ferme bientôt, alors que la saisie d'après la borne est déjà refusée.
     */
    public function testMeterClosingLaterIsSuffixedInTheFutureWithoutClaimingItTookEffect(): void
    {
        $closedOn = $this->day(10);

        $option = $this->meterOption($this->render([], metersByEnergy: ['electricity' => [$this->meter($closedOn)]]));

        self::assertStringContainsString('ferme le ' . $closedOn->format('Y-m-d'), $option);
        self::assertStringNotContainsString('fermé le', $option);
        self::assertStringContainsString('data-closed="' . $closedOn->format('Y-m-d') . '"', $option);
        self::assertStringNotContainsString('data-closed-now', $option);
    }

    /** Un compteur en service n'est suffixé d'aucune date — le cas courant. */
    public function testOpenMeterIsNeitherSuffixedNorFlagged(): void
    {
        $option = $this->meterOption($this->render([], metersByEnergy: ['electricity' => [$this->meter(null)]]));

        self::assertStringNotContainsString('data-closed', $option);
        self::assertStringNotContainsString(' — ', $option);
    }
}
