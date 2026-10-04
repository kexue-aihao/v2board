<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\PlanContentSanitizer;

class Plan extends Model
{
    protected $table = 'v2_plan';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
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
}
