<?php

namespace App\Cod\Confirmation;

use App\Models\CodCallLog;
use App\Models\CodOrder;
use Illuminate\Database\Eloquent\Collection;
use Webkul\User\Models\Admin;

/**
 * Recording of confirmation call outcomes (phase 3).
 *
 * Logging a call updates the COD workflow status of the order:
 * confirmed → `confirmed`, refused → `canceled`, no answer and
 * postponed → the order stays pending confirmation with one more
 * attempt counted (and a callback date when one was promised).
 */
interface CallLogServiceInterface
{
    /**
     * Call results, in display order.
     */
    public function results(): array;

    /**
     * Record one call for one order and update the order status. The
     * callback date is optional, even for a postponed call.
     */
    public function recordCall(CodOrder $order, Admin $confirmer, string $result, ?string $note = null, ?string $callbackAt = null): CodCallLog;

    /**
     * Calls logged for one order, newest first.
     */
    public function callsFor(CodOrder $order): Collection;

    /**
     * The most recent call logged for one order, or null.
     */
    public function lastCallFor(CodOrder $order): ?CodCallLog;
}
