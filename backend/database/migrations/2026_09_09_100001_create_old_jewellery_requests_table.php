<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('bidding_start_at')->nullable();
            $table->timestamp('bidding_end_at')->nullable();
            $table->foreignId('winning_bid_id')->nullable();
            $table->decimal('final_amount', 12, 2)->nullable();
            $table->decimal('deduction_amount', 12, 2)->nullable();
            $table->decimal('credited_amount', 12, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('bidding_start_at');
            $table->index('bidding_end_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_requests');
    }
};
