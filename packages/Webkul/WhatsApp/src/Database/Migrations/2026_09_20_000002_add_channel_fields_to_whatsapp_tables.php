<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The foreign key on lead_id relies on the unique index we are about to drop,
        // so it needs an index of its own first.
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->index('lead_id');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique(['lead_id', 'canal_origem']);

            $table->unsignedBigInteger('session_id')->nullable()->after('lead_id');
            $table->string('chat_id')->nullable();
            $table->string('contact_phone', 30)->nullable()->index();
            $table->string('contact_lid')->nullable();
            $table->string('contact_name')->nullable();
            $table->unsignedInteger('assigned_user_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_message_preview', 280)->nullable();
            $table->timestamp('last_inbound_at')->nullable();

            $table->foreign('session_id')->references('id')->on('whatsapp_sessions')->nullOnDelete();
            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['session_id', 'chat_id']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->text('content')->nullable()->change();

            $table->string('status', 20)->default('received')->after('direction');
            $table->string('source', 20)->nullable();
            $table->tinyInteger('ack')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('media_filename')->nullable();
            $table->unsignedInteger('sent_by_user_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreign('sent_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn([
                'status', 'source', 'ack', 'media_path', 'media_mime', 'media_filename',
                'sent_by_user_id', 'error', 'delivered_at', 'read_at', 'edited_at', 'revoked_at',
            ]);
        });

        DB::table('whatsapp_messages')->whereNull('content')->update(['content' => '']);

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->text('content')->nullable(false)->change();
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'chat_id']);
            $table->dropForeign(['session_id']);
            $table->dropForeign(['assigned_user_id']);
            $table->dropColumn([
                'session_id', 'chat_id', 'contact_phone', 'contact_lid', 'contact_name',
                'assigned_user_id', 'status', 'last_message_at', 'last_message_preview', 'last_inbound_at',
            ]);
            $table->unique(['lead_id', 'canal_origem']);
            $table->dropIndex(['lead_id']);
        });
    }
};
