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
        Schema::create('kanban_card_preferences', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unsigned();
            $table->string('src');
            $table->json('preferences');
            $table->timestamps();

            $table->unique(['user_id', 'src']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kanban_card_preferences');
    }
};
