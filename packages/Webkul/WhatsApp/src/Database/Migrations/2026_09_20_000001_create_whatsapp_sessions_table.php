<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->default('waha');
            $table->string('name');
            $table->string('external_id');
            $table->string('status', 30)->default('STOPPED');
            $table->string('phone', 30)->nullable();
            $table->text('credentials')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sessions');
    }
};
