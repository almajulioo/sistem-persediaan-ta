<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_details', function (Blueprint $table) {
            $table->uuid('request_detail_id')->primary();
            $table->foreignUuid('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items', 'item_id')->restrictOnDelete();
            $table->unsignedInteger('amount_request');
            $table->string('status');
            $table->unsignedInteger('amount_granted')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_details');
    }
};