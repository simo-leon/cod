<?php

namespace App\Cod\Confirmation;

use App\Models\CodCallLog;
use App\Models\CodOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

        // H4: a call may only move an order that is still waiting for
        // confirmation. Without this guard one call could resurrect a
        // canceled order or un-deliver a delivered one. The state is
        // re-checked inside the transaction on the locked row, so a
        // concurrent status change cannot slip through.
        return DB::transaction(function () use ($order, $confirmer, $result, $note, $callbackAt) {
            $locked = CodOrder::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CodOrder::STATUS_PENDING_CONFIRMATION, CodOrder::STATUS_WHATSAPP_UNCONFIRMED], true)) {
                throw new \InvalidArgumentException('Calls can only be recorded on an order pending confirmation (current status: '.$locked->status.').');
            }

            $call = CodCallLog::create([
                'cod_order_id' => $locked->id,
                'confirmer_id' => $confirmer->id,
                'result' => $result,
                'note' => $note ? trim($note) : null,
                'callback_at' => $callbackAt,
                'called_at' => now(),
            ]);

            $locked->confirmation_attempts = $locked->confirmation_attempts + 1;

            match ($result) {
                CodCallLog::RESULT_CONFIRMED => $locked->status = CodOrder::STATUS_CONFIRMED,
                CodCallLog::RESULT_REFUSED => $locked->status = CodOrder::STATUS_CANCELED,
                CodCallLog::RESULT_POSTPONED => $locked->callback_at = $callbackAt,
                default => null,
            };

            $locked->save();

            return $call;
        });
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
