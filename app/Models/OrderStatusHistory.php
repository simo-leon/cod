<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

class OrderStatusHistory extends Model
{
    protected $fillable = [
        'order_id',
        'old_status',
        'new_status',
        'admin_id',
        'actor_name',
        'source',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
