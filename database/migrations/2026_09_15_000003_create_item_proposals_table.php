<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_proposals', function (Blueprint $table) {
            $table->uuid('item_proposal_id')->primary();
            $table->foreignUuid('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('item_name');
            $table->string('unit');
            $table->text('reason')->nullable();
            $table->enum('status', ['diajukan', 'ditindaklanjuti', 'ditolak'])->default('diajukan');
            $table->foreignUuid('followed_up_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('followed_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_proposals');
    }
};