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
        Schema::table('token_data', function (Blueprint $table) {
            $table->tinyInteger('winner')->nullable()->after('counts')->comment('Winner token number (0-9)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('token_data', function (Blueprint $table) {
            $table->dropColumn('winner');
        });
    }
};
