<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_details', function (Blueprint $table) {
            $table->uuid('procurement_detail_id')->primary();
            $table->foreignUuid('procurement_id')->constrained('procurements', 'procurement_id')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items', 'item_id')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_details');
    }
};