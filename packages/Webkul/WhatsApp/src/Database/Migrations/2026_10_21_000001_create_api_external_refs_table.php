<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a record came from in another system (e.g. the Trello card id of an imported lead),
        // so an import can be repeated without creating duplicates.
        Schema::create('api_external_refs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->string('external_id', 100);
            $table->string('entity_type', 20)->default('lead');
            $table->unsignedBigInteger('entity_id');
            $table->timestamps();

            $table->unique(['source', 'external_id', 'entity_type'], 'api_external_refs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_external_refs');
    }
};
