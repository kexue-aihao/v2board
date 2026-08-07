<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    public const STATUS_INITIALIZING = 'initializing';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INVALIDATED = 'invalidated';

    protected $table = 'v2_payment_attempt';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_INITIALIZING, self::STATUS_PENDING], true);
    }
}
