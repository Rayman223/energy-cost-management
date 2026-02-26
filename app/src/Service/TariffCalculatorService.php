<?php

declare(strict_types=1);

namespace App\Service;

final class TariffCalculatorService
{
    /**
     * @param array<string,float> $tariff e.g. ['energy' => 0.22, 'distribution' => 0.08, 'taxes' => 0.03]
     */
    public function estimateElectricityCost(float $kwh, array $tariff): float
    {
        return $kwh * array_sum($tariff);
    }

    /**
     * @param array<string,float> $tariff e.g. ['energy' => 0.06, 'distribution' => 0.02, 'taxes' => 0.01]
     */
    public function estimateGasCost(float $kwh, array $tariff): float
    {
        return $kwh * array_sum($tariff);
    }
}
