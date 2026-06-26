<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\TariffGrid;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\Contract\TariffRepositoryInterface;

/**
 * Lecture et création des grilles tarifaires.
 */
final class TariffController
{
    public function __construct(private readonly TariffRepositoryInterface $tariffRepo)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return JsonResponse::ok([
            'electricity' => array_map($this->mapGrid(...), $this->tariffRepo->findAll('electricity')),
            'gas'         => array_map($this->mapGrid(...), $this->tariffRepo->findAll('gas')),
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
        if (!in_array($energyType, ['electricity', 'gas'], true)) {
            throw new ValidationException('energy_type must be electricity or gas');
        }

        $validFrom = Request::parseDate($request->input('valid_from'), 'valid_from');
        $validTo   = Request::optionalDate($request->input('valid_to'), 'valid_to');

        $id = $this->tariffRepo->saveGrid(
            energyType: $energyType,
            name: (string) $request->input('name'),
            validFrom: $validFrom,
            validTo: $validTo,
            lines: (array) $request->input('lines'),
        );

        return JsonResponse::ok(['ok' => true, 'id' => $id]);
    }

    /** @return array<string,mixed> */
    private function mapGrid(TariffGrid $g): array
    {
        return [
            'id'         => $g->id,
            'name'       => $g->name,
            'valid_from' => $g->validFrom->format('Y-m-d'),
            'valid_to'   => $g->validTo?->format('Y-m-d'),
            'lines'      => $g->toTariffArray(),
        ];
    }
}
