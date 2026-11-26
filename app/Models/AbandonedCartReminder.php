<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Checkout\Models\Cart;

/**
 * One row per cart that received an abandoned-cart WhatsApp
 * reminder. The unique cart_id is the once-only guarantee: the
 * command never sends a second reminder for a cart that already
 * has a row, and a failed send writes no row, so it is retried on
 * the next run.
 */
class AbandonedCartReminder extends Model
{
    protected $table = 'abandoned_cart_reminders';

    protected $fillable = [
        'cart_id',
        'phone',
        'locale',
        'message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }
}
