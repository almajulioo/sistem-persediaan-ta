<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requests', function (Blueprint $table) {
            $table->uuid('procurement_request_id')->primary();
            $table->foreignUuid('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->string('request_type');
            $table->foreignUuid('user_id')->constrained('users', 'user_id')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_requests');
    }
};