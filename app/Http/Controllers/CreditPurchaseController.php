<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Http\Requests\PurchaseCreditRequest;
use App\Money\Usd;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CreditPurchaseController extends Controller
{
    /**
     * Adds a pack of credit to the balance. The payment is fake: this is a demo.
     */
    public function __invoke(PurchaseCreditRequest $request, Billing $billing): RedirectResponse
    {
        $pack = $request->integer('pack');

        $billing->addCredit($request->user(), Usd::parse((string) $pack));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$pack} of demo credit. No real payment was made."]);

        return back();
    }
}
