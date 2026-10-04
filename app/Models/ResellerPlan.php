<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\PlanContentSanitizer;

class ResellerPlan extends Model
{
    protected $table = 'v2_reseller_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'shared_member_limit' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function getContentAttribute($value): string
    {
        return PlanContentSanitizer::sanitize((string)$value);
    }

    public function setContentAttribute($value): void
    {
        $content = is_scalar($value) || $value === null ? (string)$value : '';
        $this->attributes['content'] = PlanContentSanitizer::sanitize($content);
    }

    public function reseller()
    {
        return $this->belongsTo(ResellerAccount::class, 'reseller_id');
    }

    public function basePlan()
    {
        return $this->belongsTo(Plan::class, 'base_plan_id');
    }
}
