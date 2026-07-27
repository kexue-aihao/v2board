<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionTokenHistory extends Model
{
    protected $table = 'v2_subscription_token_history';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = true;

    // token_encrypted 刻意不加 cast 也不加 $hidden：它只在 reveal 接口里被显式解密一次，
    // 其余任何地方都不该把它读进响应，所以由调用方按需取列而不是靠模型层遮挡。
    protected $casts = [
        'user_id' => 'integer',
        'issued_at' => 'timestamp',
        'issued_at_exact' => 'boolean',
        'retired_at' => 'timestamp',
        'retired_at_exact' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
