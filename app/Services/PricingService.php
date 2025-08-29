<?php

namespace App\Services;

use App\DTO\PriceQuoteDTO;
use App\Models\PriceQuote;
use App\Models\Product;
use App\Models\SpotPrice;
use Illuminate\Support\Carbon;

class PricingService
{
    public function quote(string $sku, int $qty, int $toleranceBps = 50): PriceQuoteDTO
    {
        $product = Product::where('sku', $sku)->firstOrFail();
        $spot = SpotPrice::where('metal', $product->metal)->orderByDesc('as_of')->firstOrFail();

        // Integer money math only
        $unit = (int) round($spot->price_per_oz_cents * $product->weight_oz + $product->premium_cents);

        $q = PriceQuote::create([
            'user_id' => 1, // seeded test user
            'sku' => $sku,
            'unit_price_cents' => $unit,
            'qty' => $qty,
            'quote_expires_at' => Carbon::now('UTC')->addMinutes((int) config('app.quote_lock', 5)),
            'basis_spot_cents' => (int) $spot->price_per_oz_cents,
            'basis_version' => $spot->id,
            'tolerance_bps' => $toleranceBps,
        ]);

        return new PriceQuoteDTO((string) $q->id, $sku, $unit, $qty, $q->quote_expires_at->toIso8601String());
    }
}
