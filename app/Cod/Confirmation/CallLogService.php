<?php

namespace App\Cod\Confirmation;

use App\Models\CodCallLog;
use App\Models\CodOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Webkul\User\Models\Admin;

class CallLogService implements CallLogServiceInterface
{
    /**
     * Call results, in display order.
     */
    public function results(): array
    {
        return CodCallLog::RESULTS;
    }

    /**
     * Record one call for one order and update the order status. The
     * callback date is optional, even for a postponed call.
     */
    public function recordCall(CodOrder $order, Admin $confirmer, string $result, ?string $note = null, ?string $callbackAt = null): CodCallLog
    {
        $callbackAt = $callbackAt ? Carbon::parse($callbackAt) : null;

        $call = CodCallLog::create([
            'cod_order_id' => $order->id,
            'confirmer_id' => $confirmer->id,
            'result' => $result,
            'note' => $note ? trim($note) : null,
            'callback_at' => $callbackAt,
            'called_at' => now(),
        ]);

        $order->confirmation_attempts = $order->confirmation_attempts + 1;

        match ($result) {
            CodCallLog::RESULT_CONFIRMED => $order->status = CodOrder::STATUS_CONFIRMED,
            CodCallLog::RESULT_REFUSED => $order->status = CodOrder::STATUS_CANCELED,
            CodCallLog::RESULT_POSTPONED => $order->callback_at = $callbackAt,
            default => null,
        };

        $order->save();

        return $call;
    }

    /**
     * Calls logged for one order, newest first.
     */
    public function callsFor(CodOrder $order): Collection
    {
        return $order->callLogs()->with('confirmer')->get();
    }

    /**
     * The most recent call logged for one order, or null.
     */
    public function lastCallFor(CodOrder $order): ?CodCallLog
    {
        return $order->callLogs()->with('confirmer')->first();
    }
}
