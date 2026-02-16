<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 
        'description', 
        'start_date', 
        'format', 
        'status',
        'venues_count',
        'points_win',
        'points_draw',
        'points_loss',
        'number_of_groups'
    ];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }
}