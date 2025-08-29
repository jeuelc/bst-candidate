<?php

namespace App\Services;

use App\DTO\PendingOrderDTO;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceQuote;
use App\Models\SpotPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(private FulfillmentInventoryService $inventory) {}

    public function beginCheckout(string $quoteId, string $idempotencyKey): PendingOrderDTO
    {
        // Idempotency check
        $existingKey = IdempotencyKey::where(['key' => $idempotencyKey, 'purpose' => 'checkout'])->first();
        if ($existingKey) {
            $order = Order::where('payment_intent_id', $existingKey->id)->first();
            if ($order) {
                return new PendingOrderDTO($order->id, $order->payment_intent_id, $order->status);
            }
        }

        return DB::transaction(function () use ($quoteId, $idempotencyKey) {
            $quote = PriceQuote::lockForUpdate()->findOrFail($quoteId);

            // TODO: validate not expired
            if (Carbon::now('UTC')->greaterThan($quote->quote_expires_at)) {
                abort(response()->json(['error' => 'REQUOTE_REQUIRED'], 409));
            }

            // TODO: tolerance check against current spot
            $spot = SpotPrice::where('metal', 'LIKE', '%')->find($quote->basis_version) ?? null;
            $current = SpotPrice::where('metal', $spot?->metal)->orderByDesc('as_of')->first();
            if ($current && $quote->basis_spot_cents > 0) {
                $deltaBps = (int) floor(abs($current->price_per_oz_cents - $quote->basis_spot_cents) * 10000 / $quote->basis_spot_cents);
                if ($deltaBps > $quote->tolerance_bps) {
                    abort(response()->json(['error' => 'REQUOTE_REQUIRED'], 409));
                }
            }

            // Inventory check (external mocked)
            if (! $this->inventory->checkAvailability($quote->sku, $quote->qty)) {
                abort(response()->json(['error' => 'OUT_OF_STOCK'], 409));
            }

            // Create order
            $subtotal = $quote->unit_price_cents * $quote->qty;
            $order = Order::create([
                'user_id' => $quote->user_id,
                'total_cents' => $subtotal,
                'status' => 'pending',
                'payment_intent_id' => 'pi_'.bin2hex(random_bytes(6)),
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'sku' => $quote->sku,
                'qty' => $quote->qty,
                'unit_price_cents' => $quote->unit_price_cents,
                'subtotal_cents' => $subtotal,
            ]);

            IdempotencyKey::create(['key' => $idempotencyKey, 'purpose' => 'checkout', 'created_at' => now()]);

            return new PendingOrderDTO($order->id, $order->payment_intent_id, $order->status);
        });
    }
}
