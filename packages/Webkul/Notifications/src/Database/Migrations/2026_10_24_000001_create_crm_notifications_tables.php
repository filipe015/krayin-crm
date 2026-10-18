<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The CRM's notification center (the bell in the header). user_id null = everyone sees it.
        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40)->default('general');
            $table->string('level', 10)->default('info'); // info | success | warning | danger
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('url', 255)->nullable();
            $table->unsignedInteger('user_id')->nullable();
            // The same event must not notify twice (e.g. "goal X behind pace, 2026-10-24").
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });

        // Read state is per user: a notification for everyone is read by each person on their own.
        Schema::create('crm_notification_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_id');
            $table->unsignedInteger('user_id');
            $table->timestamp('read_at')->nullable();

            $table->primary(['notification_id', 'user_id']);
            $table->foreign('notification_id')->references('id')->on('crm_notifications')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notification_reads');
        Schema::dropIfExists('crm_notifications');
    }
};
