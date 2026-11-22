<?php

declare(strict_types=1);

namespace App\Billing;

use App\Money\Usd;
use App\Pixelforge\Rates;
use App\Pixelforge\Usage;
use Money\Money;

/**
 * Provider cost of a generation: token usage times the rates the generation was billed at.
 */
final class CostCalculator
{
    public function cost(Usage $usage, Rates $rates): Money
    {
        return Usd::sum([
            $rates->inputText->multiply($usage->inputText),
            $rates->inputImage->multiply($usage->inputImage),
            $rates->output->multiply($usage->output),
            $rates->reasoning->multiply($usage->reasoning),
        ]);
    }
}
