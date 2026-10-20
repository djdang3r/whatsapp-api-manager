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
        Schema::table('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->string('meta_connection_status', 45)->nullable()->after('status');
            $table->timestamp('meta_status_checked_at')->nullable()->after('meta_connection_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['meta_connection_status', 'meta_status_checked_at']);
        });
    }
};
