<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Group extends Model
{
    use HasFactory;

    protected $fillable = ['tournament_id', 'name'];

    public function players()
    {
        return $this->belongsToMany(Player::class, 'group_player');
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }
}
