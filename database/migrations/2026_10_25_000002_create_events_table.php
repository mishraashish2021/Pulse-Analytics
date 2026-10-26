<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');                    // client-generated; makes retries idempotent
            $table->string('name', 100);                 // "page_view" or any custom event
            $table->string('source', 10);                // browser | server (decided by which key was used)
            $table->string('anonymous_id', 64)->nullable();
            $table->string('user_id', 128)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->json('properties')->nullable();
            $table->dateTime('occurred_at');             // UTC, when it happened (client clock, clamped)
            $table->dateTime('received_at');             // UTC, when we stored it

            $table->unique(['project_id', 'event_id']);
            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'name', 'occurred_at']);
            $table->index(['project_id', 'path', 'occurred_at']);
            $table->index(['project_id', 'anonymous_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
