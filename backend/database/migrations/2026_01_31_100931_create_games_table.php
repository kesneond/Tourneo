<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            
            // Hráč 1 a Hráč 2
            $table->foreignId('player1_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('players')->cascadeOnDelete();
            
            $table->integer('score1')->nullable();
            $table->integer('score2')->nullable();
            $table->string('status')->default('scheduled'); // scheduled, finished
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
