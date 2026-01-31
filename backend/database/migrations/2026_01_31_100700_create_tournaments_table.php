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
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            
            // --- TENTO ŘÁDEK TAM MUSÍ BÝT ---
            $table->string('name'); 
            // --------------------------------
            
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->string('format')->default('round_robin');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
