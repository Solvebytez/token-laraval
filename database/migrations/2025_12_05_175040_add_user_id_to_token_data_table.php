<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('token_data', function (Blueprint $table) {
            // Check if user_id column exists
            if (!Schema::hasColumn('token_data', 'user_id')) {
                $table->foreignId('user_id')->after('id')->nullable()->constrained()->onDelete('cascade');
                $table->index('user_id');
            }
        });

        // Check if foreign key exists, if not create it
        $foreignKeyExists = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'token_data' 
            AND CONSTRAINT_NAME = 'token_data_user_id_foreign'
        ");

        if (empty($foreignKeyExists) && Schema::hasColumn('token_data', 'user_id')) {
            // Foreign key doesn't exist but column does, add it
            DB::statement('
                ALTER TABLE token_data 
                ADD CONSTRAINT token_data_user_id_foreign 
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ');
        }

        // Ensure index exists
        $indexExists = DB::select("
            SHOW INDEX FROM token_data WHERE Key_name = 'token_data_user_id_index'
        ");

        if (empty($indexExists) && Schema::hasColumn('token_data', 'user_id')) {
            DB::statement('CREATE INDEX token_data_user_id_index ON token_data(user_id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('token_data', function (Blueprint $table) {
            if (Schema::hasColumn('token_data', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
