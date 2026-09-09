<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_wallet_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('old_jewellery_request_id')->unique()->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('deduction_amount', 12, 2);
            $table->decimal('credited_amount', 12, 2);
            $table->decimal('remaining_amount', 12, 2);
            $table->timestamp('credited_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active'); // active | partially_used | used | expired
            $table->timestamp('reminder_3d_sent_at')->nullable();
            $table->timestamp('reminder_1d_sent_at')->nullable();
            $table->timestamp('reminder_0d_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_wallet_credits');
    }
};
