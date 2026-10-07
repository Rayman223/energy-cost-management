<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\LoadProfileRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux dépôt de profils de charge : poids injectés en dur, sans base (#93).
 *
 * Les appels sont enregistrés dans `$calls` pour que les tests puissent vérifier non
 * seulement le résultat, mais le fait que le profil du CONTRAT a bien été demandé —
 * un profil chargé sous le mauvais code donnerait un prix plausible et faux.
 */
final class FakeLoadProfileRepository implements LoadProfileRepositoryInterface
{
    /** @var list<array{code: string, country: string, resolution: int}> */
    public array $calls = [];

    /**
     * @param array<string, float> $weights Poids servis pour toute fenêtre demandée.
     * @param list<string> $codes Codes annoncés comme disponibles.
     * @param int $servedResolution Résolution pour laquelle $weights est servi ; toute
     *        autre reçoit une map vide, ce qui permet de tester le repli 15 → 60.
     */
    public function __construct(
        public array $weights = [],
        public array $codes = [],
        public int $servedResolution = 15,
    ) {
    }

    public function weightsBetween(
        string $code,
        string $country,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $resolutionMin = 15,
    ): array {
        $this->calls[] = ['code' => $code, 'country' => $country, 'resolution' => $resolutionMin];

        if ($resolutionMin !== $this->servedResolution) {
            return [];
        }

        // Fenêtre respectée comme le ferait le SQL : les tests qui vérifient les bornes
        // ne doivent pas voir des poids hors période.
        $fromKey = $from->format('Y-m-d H:i:00');
        $toKey   = $to->format('Y-m-d H:i:00');

        return array_filter(
            $this->weights,
            static fn (float $w, string $slot): bool => $slot >= $fromKey && $slot < $toKey,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function availableCodes(string $country): array
    {
        return $this->codes;
    }
}
