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
        Schema::create('requests', function (Blueprint $table) {
            $table->uuid('request_id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();
            $table->date('date');
            $table->string('request_number')->unique();
            $table->string('type');
            $table->enum('status', ['submitted', 'approved_by_head', 'processed_by_staff', 'completed', 'rejected'])->default('submitted');
            $table->string('bast_number')->nullable();
            $table->string('nd_number')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
