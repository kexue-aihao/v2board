<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\NoticeContentSanitizer;

class Notice extends Model
{
    protected $table = 'v2_notice';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'tags' => 'array'
    ];

    public function getContentAttribute($value): string
    {
        return NoticeContentSanitizer::sanitize((string)$value);
    }

    public function setContentAttribute($value): void
    {
        $content = is_scalar($value) || $value === null ? (string)$value : '';
        $this->attributes['content'] = NoticeContentSanitizer::sanitize($content);
    }
}
