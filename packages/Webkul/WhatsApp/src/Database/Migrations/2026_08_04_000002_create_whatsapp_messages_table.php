<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('sender_name')->nullable();
            $table->text('content');
            $table->string('message_type')->default('text');
            $table->string('external_message_id')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
