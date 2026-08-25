<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opname_details', function (Blueprint $table) {
            $table->uuid('opname_detail_id')->primary();
            $table->foreignUuid('opname_id')->constrained('stock_opnames', 'opname_id')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items', 'item_id')->restrictOnDelete();
            $table->unsignedInteger('stock_stored');
            $table->unsignedInteger('stock_real');
            $table->integer('difference');
            $table->text('adjustment_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opname_details');
    }
};