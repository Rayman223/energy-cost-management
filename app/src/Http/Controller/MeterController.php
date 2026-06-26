<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Service\MeterApiService;

/**
 * Lecture temps réel des compteurs (dongles HomeWizard).
 * Un compteur injoignable n'échoue pas la requête : son erreur est rapportée.
 */
final class MeterController
{
    public function __construct(
        private readonly MeterApiService $meterApi,
        private readonly string $driesUrl,
        private readonly string $solarUrl,
    ) {
    }

    public function live(Request $request): JsonResponse
    {
        $result = ['ok' => true, 'timestamp' => date('c')];

        try {
            $dries = $this->meterApi->fetchJson($this->driesUrl);
            $result['dries_w'] = $this->meterApi->readNumericValue($dries, ['active_power_w']);
        } catch (\Throwable $e) {
            $result['dries_error'] = $e->getMessage();
        }

        try {
            $solar = $this->meterApi->fetchJson($this->solarUrl);
            $result['solar_w'] = $this->meterApi->readNumericValue($solar, ['active_power_w']);
        } catch (\Throwable $e) {
            $result['solar_error'] = $e->getMessage();
        }

        return JsonResponse::ok($result);
    }
}
