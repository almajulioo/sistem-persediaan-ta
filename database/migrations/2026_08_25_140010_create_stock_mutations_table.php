<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->uuid('mutation_id')->primary();
            $table->foreignUuid('item_id')->constrained('items', 'item_id')->restrictOnDelete();
            $table->string('mutation_type');
            $table->integer('amount');
            $table->date('mutation_date');
            $table->uuid('reference')->nullable();
            $table->string('reference_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mutations');
    }
};