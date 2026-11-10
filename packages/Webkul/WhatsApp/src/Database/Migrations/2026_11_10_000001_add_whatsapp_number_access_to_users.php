<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Access by WhatsApp number: a restricted user only sees (and answers) the conversations of the numbers listed in
        // user_whatsapp_sessions. Everybody starts unrestricted, as before.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_restricted')->default(false);
        });

        Schema::create('user_whatsapp_sessions', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('session_id');

            $table->primary(['user_id', 'session_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('session_id')->references('id')->on('whatsapp_sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_whatsapp_sessions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp_restricted');
        });
    }
};
