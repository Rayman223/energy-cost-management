<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\BatteryReadingController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\BatteryReadingRepository;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeBatteryRepository;

/**
 * Résolution de la batterie visée (#26).
 *
 * C'est le seul endroit de l'application où une écriture doit choisir SA cible :
 * se tromper de batterie rattache des index au mauvais matériel et fausse le
 * bilan sans qu'aucune erreur n'apparaisse. Les règles sont donc testées seules,
 * avant toute écriture : la fabrique de repository ci-dessous n'ouvre aucune
 * base, elle annonce la cible retenue en levant une sentinelle. Une résolution
 * refusée n'atteint donc jamais la fabrique — et n'écrit rien.
 */
final class BatteryReadingControllerTest extends TestCase
{
    /** @param list<int> $fleet */
    private function controller(array $fleet): BatteryReadingController
    {
        return new BatteryReadingController(
            new FakeBatteryRepository($fleet),
            static function (int $batteryId): BatteryReadingRepository {
                throw new \RuntimeException('resolved:' . $batteryId);
            },
            'Europe/Brussels',
        );
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return new Request('POST', ['action' => 'battery_entry'], $body);
    }

    public function testRefusesWhenNoBatteryIsDeclared(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No battery declared');

        $this->controller([])->entry($this->post(['discharge' => 1000.0]));
    }

    /**
     * Plusieurs batteries et aucune désignée : refuser plutôt que deviner. Le
     * message énumère les identifiants — sans eux, l'utilisateur d'un agent n'a
     * aucun moyen de savoir quoi envoyer.
     */
    public function testRefusesAnAmbiguousFleetAndListsTheIds(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('battery_id is required (several batteries: 3, 7)');

        $this->controller([3, 7])->entry($this->post(['discharge' => 1000.0]));
    }

    public function testRefusesABatteryIdThatIsNotOurs(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown battery_id');

        $this->controller([3])->entry($this->post(['battery_id' => 99, 'discharge' => 1000.0]));
    }

    public function testRefusesANonNumericBatteryId(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown battery_id');

        $this->controller([3])->entry($this->post(['battery_id' => 'abc', 'discharge' => 1000.0]));
    }

    /**
     * Parc d'une seule batterie : `battery_id` reste facultatif. L'exiger
     * obligerait chaque agent à connaître un identifiant de base de données pour
     * l'installation la plus courante qui soit.
     *
     * La sentinelle de la fabrique nomme la cible retenue : c'est la preuve que la
     * résolution est allée au bout et a bien désigné la batterie 4.
     */
    public function testASingleBatteryIsResolvedImplicitly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolved:4');

        $this->controller([4])->entry($this->post(['discharge' => 1000.0]));
    }

    /** Un identifiant explicite et légitime désigne bien CETTE batterie. */
    public function testAnExplicitBatteryIdWins(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolved:7');

        $this->controller([3, 7])->entry($this->post(['battery_id' => 7, 'discharge' => 1000.0]));
    }
}
