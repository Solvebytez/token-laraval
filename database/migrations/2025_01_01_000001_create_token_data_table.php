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
        Schema::create('token_data', function (Blueprint $table) {
            $table->id();
            $table->string('time_slot_id', 50)->unique()->comment('Format: YYYY-MM-DD_HH:MM');
            $table->date('date')->index();
            $table->time('time_slot')->index()->comment('Time slot in HH:MM format');
            $table->json('entries')->comment('Array of token entries');
            $table->json('counts')->comment('Counts for each token number (0-9)');
            $table->timestamp('saved_at')->useCurrent();
            $table->timestamps();
            
            // Indexes for faster queries
            $table->index(['date', 'time_slot']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_data');
    }
};
