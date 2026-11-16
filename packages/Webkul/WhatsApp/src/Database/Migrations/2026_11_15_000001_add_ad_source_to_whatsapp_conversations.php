<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Click-to-WhatsApp ad that opened the conversation, kept from the first message that
 * carried it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->string('ad_source_id', 191)->nullable()->after('last_inbound_at');
            $table->text('ad_source_url')->nullable()->after('ad_source_id');
            $table->string('ad_source_type', 50)->nullable()->after('ad_source_url');
            $table->string('ad_title', 500)->nullable()->after('ad_source_type');
            $table->text('ad_body')->nullable()->after('ad_title');
            $table->string('ad_ctwa_clid', 500)->nullable()->after('ad_body');
            $table->timestamp('ad_captured_at')->nullable()->after('ad_ctwa_clid');

            $table->index('ad_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['ad_source_id']);
            $table->dropColumn(['ad_source_id', 'ad_source_url', 'ad_source_type', 'ad_title', 'ad_body', 'ad_ctwa_clid', 'ad_captured_at']);
        });
    }
};
