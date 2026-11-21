<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp message sent or received by the COD System (phase 4).
 *
 * Audit trail for the confirmation flow: every outbound confirmation /
 * reminder and every inbound customer reply is recorded here with the
 * provider used, so a dispute ("I never got the message", "I
 * confirmed!") can be checked without asking Meta or Evolution.
 *
 * Directions: out (we sent it), in (the customer sent it).
 * Statuses: sent, failed, received, logged (log provider: written to
 * the Laravel log only, nothing was actually sent).
 *
 * `message_id` is the provider's message id (Meta wamid, Evolution key
 * id) when one was returned. The (provider, message_id) pair is unique
 * for inbound messages so a redelivered webhook is ignored instead of
 * updating the order twice. `error` holds the provider error text on
 * failed sends — never a token.
 */
class CodWhatsAppMessage extends Model
{
    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IN = 'in';

    public const DIRECTIONS = [
        self::DIRECTION_OUT,
        self::DIRECTION_IN,
    ];

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_LOGGED = 'logged';

    public const STATUSES = [
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_RECEIVED,
        self::STATUS_LOGGED,
    ];

    protected $table = 'cod_whatsapp_messages';

    protected $guarded = [];

    public function codOrder(): BelongsTo
    {
        return $this->belongsTo(CodOrder::class);
    }
}
