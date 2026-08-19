<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class GameRoom extends Model
{
    protected $table = 'v2_game_room';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['players' => 'array', 'result' => 'array', 'expires_at' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
