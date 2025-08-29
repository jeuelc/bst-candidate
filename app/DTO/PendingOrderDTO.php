<?php

namespace App\DTO;

class PendingOrderDTO
{
    public function __construct(
        public int $order_id,
        public string $payment_intent_id,
        public string $status
    ) {}
}
