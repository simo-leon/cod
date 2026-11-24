<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\Admin;

/**
 * One recorded money movement for a souk task.
 *
 * - advance: money given to the buyer before / at purchase time
 *   (the "advance payment" model).
 * - reimbursement: money paid back to the buyer after the goods
 *   were received and verified (the "reimbursement on delivery"
 *   model).
 *
 * No payment is ever created automatically: the controller records
 * every payment explicitly (who paid, how much, when, why), and a
 * task becomes "paid" only when the recorded payments cover its
 * purchase price.
 */
class SoukPayment extends Model
{
    public const TYPE_ADVANCE = 'advance';

    public const TYPE_REIMBURSEMENT = 'reimbursement';

    public const TYPES = [
        self::TYPE_ADVANCE,
        self::TYPE_REIMBURSEMENT,
    ];

    protected $fillable = [
        'souk_task_id',
        'buyer_id',
        'paid_by',
        'amount',
        'type',
        'note',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(SoukTask::class, 'souk_task_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'buyer_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by');
    }
}
