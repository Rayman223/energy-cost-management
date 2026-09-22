<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Domain\Meter;
use App\View\ViewFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Page /meters (#55). Le test porte sur le RENDU, parce que c'est lui qui porte
 * trois décisions qu'aucun autre test ne verrait échouer :
 *
 *   - un compteur non nommé se lit dans la langue du lecteur — le libellé est
 *     dérivé à l'affichage, jamais figé en base ;
 *   - une énergie ayant atteint son quota sort du sélecteur, plutôt que d'être
 *     proposée pour n'être refusée qu'après validation ;
 *   - l'intention de suppression vit dans un champ caché et non sur le bouton :
 *     confirm.js relance l'envoi par `requestSubmit()`, qui ne transmet ni le nom
 *     ni la valeur du bouton pressé. Un retour en arrière ici produirait un POST
 *     sans action — donc une page qui se recharge sans rien supprimer et sans
 *     rien dire.
 */
final class MetersPageTest extends TestCase
{
    private const MAX = 5;

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @param list<Meter>     $meters
     * @param array<int, int> $readingCounts
     */
    private function render(
        array $meters,
        array $readingCounts = [],
        ?Meter $editing = null,
        int $maxPerEnergy = self::MAX,
        string $locale = 'fr',
    ): string {
        $counts = array_fill_keys(Meter::ENERGIES, 0);
        foreach ($meters as $meter) {
            ++$counts[$meter->energyType];
        }

        $view = ViewFactory::create(\dirname(__DIR__, 3) . '/app/templates', $locale);

        return $view->render('meters', [
            'oidcEnabled'    => false,
            'discordUrl'     => null,
            'donateUrl'      => null,
            'adsenseClient'  => null,
            'error'          => null,
            'success'        => null,
            'isAdmin'        => false,
            'meters'         => $meters,
            'readingCounts'  => $readingCounts,
            'countsByEnergy' => $counts,
            'maxPerEnergy'   => $maxPerEnergy,
            'editing'        => $editing,
            'today'          => $this->at('2026-09-06'),
            'available'      => ['fr', 'en', 'nl', 'de'],
            'timezone'       => 'Europe/Brussels',
        ]);
    }

    public function testEmptyFleetInvitesToCreateAndOffersTheThreeEnergies(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('Aucun compteur déclaré', $html);
        foreach (Meter::ENERGIES as $energy) {
            self::assertStringContainsString('value="' . $energy . '"', $html);
        }
    }

    public function testUnnamedMeterIsLabelledInTheReadersLanguage(): void
    {
        $meters = [new Meter(id: 1, energyType: 'water')];

        // Même ligne en base, deux libellés à l'écran : c'est tout l'intérêt du
        // libellé dérivé. Écrire « Compteur d'eau » en base le servirait tel quel
        // à un lecteur néerlandophone.
        self::assertStringContainsString('Compteur d&#039;eau', $this->render($meters));
        self::assertStringContainsString('Watermeter', $this->render($meters, locale: 'nl'));
    }

    public function testNamedMeterKeepsItsOwnLabel(): void
    {
        $html = $this->render([new Meter(id: 1, energyType: 'electricity', label: 'Atelier')]);

        self::assertStringContainsString('Atelier', $html);
        self::assertStringNotContainsString('Compteur électrique', $html);
    }

    /**
     * Le nombre de relevés est ce que la suppression emporte : il doit figurer
     * dans la phrase de confirmation, pas seulement dans le tableau.
     */
    public function testDeleteConfirmationAnnouncesTheReadingCount(): void
    {
        $html = $this->render([new Meter(id: 7, energyType: 'gas')], [7 => 128]);

        self::assertStringContainsString('Ses 128 relevés', $html);
    }

    public function testDeleteIntentLivesInAHiddenFieldNotOnTheButton(): void
    {
        $html = $this->render([new Meter(id: 7, energyType: 'gas')]);

        self::assertStringContainsString('<input type="hidden" name="action" value="delete">', $html);
        self::assertStringContainsString('data-confirm-danger', $html);
        // Le bouton ne porte AUCUN name : requestSubmit() ne le transmettrait pas.
        self::assertStringNotContainsString('<button type="submit" name=', $html);
    }

    public function testSaturatedEnergyDisappearsFromTheSelector(): void
    {
        $meters = [];
        for ($i = 1; $i <= self::MAX; ++$i) {
            $meters[] = new Meter(id: $i, energyType: 'electricity');
        }

        $html = $this->render($meters);

        self::assertStringNotContainsString('value="electricity"', $html);
        self::assertStringContainsString('value="gas"', $html);
        self::assertStringContainsString('value="water"', $html);
        // Le quota est affiché : un refus sans chiffre visible serait incompréhensible.
        self::assertStringContainsString('5 / 5', $html);
    }

    public function testFormDisappearsWhenEveryEnergyIsSaturated(): void
    {
        $meters = [];
        $id     = 0;
        foreach (Meter::ENERGIES as $energy) {
            for ($i = 0; $i < 2; ++$i) {
                $meters[] = new Meter(id: ++$id, energyType: $energy);
            }
        }

        $html = $this->render($meters, maxPerEnergy: 2);

        self::assertStringContainsString('limite de 2 compteurs pour chaque énergie', $html);
        self::assertStringNotContainsString('name="energy_type"', $html);
    }

    /**
     * Énergie figée à l'édition : la changer sur un compteur qui a des relevés
     * rendrait sa série incohérente sans que rien ne le signale. Le champ est
     * rendu désactivé, donc non posté — et la route reprend de toute façon
     * l'énergie de l'existant.
     */
    public function testEditingFreezesTheEnergy(): void
    {
        $meter = new Meter(id: 3, energyType: 'gas', label: 'Cuisine');

        $html = $this->render([$meter], editing: $meter);

        self::assertStringContainsString('Modifier un compteur', $html);
        self::assertStringNotContainsString('name="energy_type"', $html);
        self::assertStringContainsString('L&#039;énergie ne peut plus changer', $html);
        self::assertStringContainsString('value="Cuisine"', $html);
    }

    public function testClosedMeterIsFlaggedWithItsDate(): void
    {
        $closed = new Meter(id: 4, energyType: 'electricity', label: 'Ancien', closedOn: $this->at('2026-01-15'));

        $html = $this->render([$closed]);

        self::assertStringContainsString('fermé le 2026-01-15', $html);
        self::assertStringContainsString('is-closed', $html);
        self::assertStringContainsString('mtr-badge--closed', $html);
    }

    /**
     * Un compteur fermé dans le futur est encore ouvert : la borne est exclue (#1) —
     * donc ni grisage, ni badge ambre. Mais la DATE doit se voir (#69) : sans elle,
     * programmer une fermeture ne produisait aucun retour à l'écran, et rien ne
     * distinguait une saisie enregistrée d'une saisie perdue.
     */
    public function testMeterClosingLaterShowsItsDateWithoutBeingGreyedOut(): void
    {
        $future = new Meter(id: 5, energyType: 'electricity', closedOn: $this->at('2027-01-01'));

        $html = $this->render([$future]);

        self::assertStringContainsString('ferme le 2027-01-01', $html);
        self::assertStringNotContainsString('is-closed', $html);
        self::assertStringNotContainsString('mtr-badge--closed', $html);
    }

    /** Sans date de fermeture, aucun badge — le cas de loin le plus courant. */
    public function testOpenMeterCarriesNoClosureBadge(): void
    {
        self::assertStringNotContainsString('mtr-badge', $this->render([new Meter(id: 6, energyType: 'water')]));
    }
}
