<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerReviewLog extends Model
{
    protected $table = 'v2_reseller_review_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'timestamp',
    ];

    public function reseller()
    {
        return $this->belongsTo(ResellerAccount::class, 'reseller_id');
    }
}
