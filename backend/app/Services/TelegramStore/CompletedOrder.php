<?php

namespace App\Services\TelegramStore;

use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;

final class CompletedOrder
{
    public function __construct(
        public readonly TelegramStoreOrder $order,
        public readonly string $code,
        public readonly ?TelegramStoreCustomer $referrer,
        public readonly int $referralBonus,
        /** A renewal order whose code already went onto the account. */
        public readonly ?Subscription $renewed = null,
    ) {}
}
