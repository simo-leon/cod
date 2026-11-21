<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\Admin;

/**
 * One confirmation call logged for a COD order (phase 3).
 *
 * Results:
 * - confirmed: the customer confirmed; the order moves to `confirmed`.
 * - no_answer: nobody answered; the order stays pending confirmation and
 *   the attempt counter grows.
 * - refused: the customer refused; the order moves to `canceled`.
 * - postponed: the customer asked to be called back; the order stays
 *   pending confirmation, with `callback_at` when a date was promised.
 */
class CodCallLog extends Model
{
    public const RESULT_CONFIRMED = 'confirmed';

    public const RESULT_NO_ANSWER = 'no_answer';

    public const RESULT_REFUSED = 'refused';

    public const RESULT_POSTPONED = 'postponed';

    public const RESULTS = [
        self::RESULT_CONFIRMED,
        self::RESULT_NO_ANSWER,
        self::RESULT_REFUSED,
        self::RESULT_POSTPONED,
    ];

    protected $guarded = [];

    protected $casts = [
        'callback_at' => 'datetime',
        'called_at' => 'datetime',
    ];

    public function codOrder(): BelongsTo
    {
        return $this->belongsTo(CodOrder::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmer_id');
    }
}
