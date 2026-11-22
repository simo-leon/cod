<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Sales\Models\Order;

/**
 * One delivery parcel sent (or attempted) to a delivery provider for an
 * order. The provider token is never stored here — only the request
 * payload and the provider's response.
 */
class DeliveryParcel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'tracking_events' => 'array',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
