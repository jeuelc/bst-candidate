<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest;
use App\Services\PricingService;

class QuoteController extends Controller
{
    public function __construct(private PricingService $pricing) {}

    public function store(QuoteRequest $req)
    {
        $dto = $this->pricing->quote($req->sku, (int) $req->qty, (int) env('PRICE_TOLERANCE_BPS', 50));

        return response()->json([
            'quote_id' => $dto->id,
            'unit_price_cents' => $dto->unit_price_cents,
            'quote_expires_at' => $dto->quote_expires_at,
        ]);
    }
}
