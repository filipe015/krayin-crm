<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each variable ({{1}}, {{2}}, ...) of an approved template means in the CRM.
        // Lives only here: Meta never sees it, so it can be changed for approved templates too.
        Schema::create('whatsapp_template_variables', function (Blueprint $table) {
            $table->id();
            $table->string('template_name', 512);
            $table->string('template_language', 20);
            $table->json('sources');
            $table->timestamps();

            $table->unique(['template_name', 'template_language'], 'wa_tpl_vars_name_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_template_variables');
    }
};
