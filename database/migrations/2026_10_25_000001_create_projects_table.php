<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('public_key', 64)->unique();        // safe to embed in the browser
            $table->string('secret_key_hash', 64)->unique();   // sha256 of the server key; the key itself is never stored
            $table->json('allowed_origins')->nullable();       // null/[] = any origin
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
