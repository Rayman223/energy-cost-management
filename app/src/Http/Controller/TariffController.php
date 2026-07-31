<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\TariffGrid;
use App\Domain\TariffLineCatalog;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\Contract\TariffRepositoryInterface;

/**
 * Lecture et création des grilles tarifaires.
 */
final class TariffController
{
    private const ENERGY_TYPES = ['electricity', 'gas', 'water'];

    public function __construct(private readonly TariffRepositoryInterface $tariffRepo)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return JsonResponse::ok([
            'electricity' => array_map($this->mapGrid(...), $this->tariffRepo->findAll('electricity')),
            'gas'         => array_map($this->mapGrid(...), $this->tariffRepo->findAll('gas')),
            'water'       => array_map($this->mapGrid(...), $this->tariffRepo->findAll('water')),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        foreach (['energy_type', 'name', 'valid_from', 'lines'] as $field) {
            if (empty($request->input($field))) {
                throw new ValidationException("Missing field: $field");
            }
        }

        $energyType = (string) $request->input('energy_type');
        if (!in_array($energyType, self::ENERGY_TYPES, true)) {
            throw new ValidationException('energy_type must be electricity, gas or water');
        }

        $validFrom = Request::parseDate($request->input('valid_from'), 'valid_from');
        $validTo   = Request::optionalDate($request->input('valid_to'), 'valid_to');

        $vatRaw  = $request->input('vat_rate');
        $vatRate = is_numeric($vatRaw) ? (float) $vatRaw : 21.0;
        if ($vatRate < 0.0 || $vatRate > 100.0) {
            throw new ValidationException('vat_rate must be between 0 and 100');
        }

        // Mode tarifaire (#245) : facultatif et non validé ici, comme le reste des
        // champs de grille importés par l'API — le repository applique la liste
        // blanche et ignore le champ hors électricité. Absent ⇒ 'fixed', qui
        // reconduit le comportement des intégrations existantes.
        $pricingMode = (string) ($request->input('pricing_mode') ?? TariffGrid::PRICING_MODE_DEFAULT);

        // `Missing field: lines` ne couvre que le tableau absent ou vide. Un payload
        // dont AUCUN montant n'est numérique traverse normalizeLines() sans laisser
        // une seule ligne : la grille était enregistrée vide, puis sélectionnée comme
        // active sur sa période, ramenant les coûts à 0 en silence. Refus explicite,
        // avant l'appel au repository (donc avant sa transaction).
        $lines = $this->normalizeLines($energyType, (array) $request->input('lines'));
        if ($lines === []) {
            throw new ValidationException('At least one valid tariff line is required');
        }

        $id = $this->tariffRepo->saveGrid(
            energyType: $energyType,
            name: (string) $request->input('name'),
            validFrom: $validFrom,
            validTo: $validTo,
            lines: $lines,
            vatRate: $vatRate,
            pricingMode: $pricingMode,
        );

        return JsonResponse::ok(['ok' => true, 'id' => $id]);
    }

    /**
     * Convertit des lignes plates `clé => montant` (format historique de l'API)
     * en lignes structurées, le kind étant déduit du catalogue (clé inconnue →
     * per_kwh). Rétrocompat des intégrations existantes.
     *
     * @param  array<string, mixed> $rawLines
     * @return list<array{key: string, amount: float, kind: string, label: ?string, category: ?string}>
     */
    private function normalizeLines(string $energyType, array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $key => $amount) {
            if (!is_numeric($amount)) {
                continue;
            }
            $key      = (string) $key;
            $lines[] = [
                'key'      => $key,
                'amount'   => (float) $amount,
                'kind'     => TariffLineCatalog::kindFor($energyType, $key)->value,
                'label'    => null,
                'category' => null, // dérivée du kind à l'affichage (API plate, sans catégorie)
            ];
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    private function mapGrid(TariffGrid $g): array
    {
        return [
            'id'           => $g->id,
            'name'         => $g->name,
            'valid_from'   => $g->validFrom->format('Y-m-d'),
            'valid_to'     => $g->validTo?->format('Y-m-d'),
            'lines'        => $g->toTariffArray(),
            'country'      => $g->country,
            'currency'     => $g->currency,
            'vat_rate'     => $g->vatRate,
            'pricing_mode' => $g->pricingMode,
            'shared'       => $g->isShared(),
        ];
    }
}
