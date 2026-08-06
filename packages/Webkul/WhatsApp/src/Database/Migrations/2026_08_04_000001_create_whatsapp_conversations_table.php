<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('lead_id')->nullable();
            $table->string('canal_origem');
            $table->string('external_conversation_id')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->unique(['lead_id', 'canal_origem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
