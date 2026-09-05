<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_details', function (Blueprint $table) {
            $table->id("request_detail_id");
            $table->timestamps();
                $table->foreignUuid('request_id')->constrained('requests', 'request_id')->cascadeOnDelete();
                $table->foreignUuid('item_id')->constrained('items', 'item_id', 'fk_req_details_item_id')->restrictOnDelete();
            $table->integer('quantity_requested')->unsigned();
            $table->integer('quantity_approved')->unsigned()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_details');
    }
};
