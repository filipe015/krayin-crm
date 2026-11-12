<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the green WhatsApp buttons on leads take the user: "crm" (conversation in the Inbox) or "web" (WhatsApp Web).
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_open_mode', 10)->default('crm');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whatsapp_open_mode');
        });
    }
};
