<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Defaultně nastavíme: Výhra 3, Remíza 1, Prohra 0
            $table->integer('points_win')->default(3);
            $table->integer('points_draw')->default(1);
            $table->integer('points_loss')->default(0);
        });
    }

    public function down()
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['points_win', 'points_draw', 'points_loss']);
        });
    }
};
