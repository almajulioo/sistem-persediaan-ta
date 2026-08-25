<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('item_id')->primary();
            $table->string('item_code')->unique();
            $table->string('item_name');
            $table->string('unit');
            $table->unsignedInteger('stock_current')->default(0);
            $table->unsignedInteger('stock_min')->default(0);
            $table->unsignedInteger('stock_max')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};