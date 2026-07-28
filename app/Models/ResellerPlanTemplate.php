<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerPlanTemplate extends Model
{
    protected $table = 'v2_reseller_plan_template';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'base_plan_id');
    }
}
