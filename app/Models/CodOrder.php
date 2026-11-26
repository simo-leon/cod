<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

/**
 * One store order imported into the COD System.
 *
 * `external_id` is the original store order identifier (the order
 * increment_id) and is unique: every import path (webhook, API endpoint,
 * scheduled import) is idempotent — receiving the same store order twice
 * updates the single record instead of creating a second one.
 *
 * The COD workflow status lives here and is owned by the COD System once
 * the record leaves `pending_confirmation`; the original store order
 * status is kept in `store_status` for reference.
 */
class CodOrder extends Model
{
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    /**
     * The customer never replied on WhatsApp: the confirmation message
     * (and the configured reminders) went unanswered, and the
     * conversion deadline passed. The order stays here for the team to
     * call the customer by phone — it is NEVER canceled automatically.
     */
    public const STATUS_WHATSAPP_UNCONFIRMED = 'whatsapp_unconfirmed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_SENT_TO_DELIVERY = 'sent_to_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [
        self::STATUS_PENDING_CONFIRMATION,
        self::STATUS_WHATSAPP_UNCONFIRMED,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELED,
        self::STATUS_SENT_TO_DELIVERY,
        self::STATUS_DELIVERED,
        self::STATUS_RETURNED,
    ];

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_API = 'api';

    public const SOURCE_IMPORT = 'import';

    /**
     * Created from a WhatsApp product inquiry: the customer asked
     * about a product on WhatsApp, the AI collected the information
     * and the customer confirmed — there is no store checkout behind
     * it, so order_id is null and external_id is a WA-... id.
     */
    public const SOURCE_WHATSAPP = 'whatsapp';

    public const SOURCES = [
        self::SOURCE_WEBHOOK,
        self::SOURCE_API,
        self::SOURCE_IMPORT,
        self::SOURCE_WHATSAPP,
    ];

    protected $fillable = [
        'order_id',
        'external_id',
        'status',
        'assigned_to',
        'assigned_at',
        'confirmation_attempts',
        'callback_at',
        'confirmation_sent_at',
        'reminder_sent_at',
        'reminder_count',
        'whatsapp_unconfirmed_at',
        'whatsapp_provider',
        'last_reply_at',
        'shipped_notification_sent_at',
        'out_for_delivery_notification_sent_at',
        'delivered_notification_sent_at',
        'returned_notification_sent_at',
        'store_status',
        'customer_name',
        'customer_phone',
        'customer_phone_normalized',
        'customer_email',
        'city',
        'address',
        'grand_total',
        'currency_code',
        'items_count',
        'source',
        'payload',
        'stock_deducted',
        'stock_deductions',
    ];

    protected $casts = [
        'grand_total' => 'decimal:2',
        'payload' => 'array',
        'stock_deducted' => 'boolean',
        'stock_deductions' => 'array',
        'assigned_at' => 'datetime',
        'callback_at' => 'datetime',
        'confirmation_attempts' => 'integer',
        'confirmation_sent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'reminder_count' => 'integer',
        'whatsapp_unconfirmed_at' => 'datetime',
        'last_reply_at' => 'datetime',
        // Phase 6: one customer notification per delivery event; set
        // only on a successful send, so a failed send is retried and
        // a sent one is never repeated.
        'shipped_notification_sent_at' => 'datetime',
        'out_for_delivery_notification_sent_at' => 'datetime',
        'delivered_notification_sent_at' => 'datetime',
        'returned_notification_sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The confirmer this order is assigned to (phase 3), if any.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_to');
    }

    /**
     * Confirmation calls logged for this order, newest first.
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CodCallLog::class)->latest('called_at');
    }

    /**
     * WhatsApp messages sent to / received from the customer for this
     * order (phase 4), newest first.
     */
    public function whatsappMessages(): HasMany
    {
        return $this->hasMany(CodWhatsAppMessage::class)->latest();
    }
}
