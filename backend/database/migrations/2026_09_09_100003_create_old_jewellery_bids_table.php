<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_jewellery_request_id')->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->string('bidder_type'); // vendor | admin
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('old_jewellery_vendor_invitations')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_valid')->default(true);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('old_jewellery_request_id');
            $table->index('submitted_at');
            $table->unique(['old_jewellery_request_id', 'vendor_id'], 'oj_bids_request_vendor_unique');
        });

        // Add the winning_bid_id FK now that old_jewellery_bids exists (chicken-and-egg
        // with old_jewellery_requests, created first above).
        Schema::table('old_jewellery_requests', function (Blueprint $table) {
            $table->foreign('winning_bid_id')->references('id')->on('old_jewellery_bids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('old_jewellery_requests', function (Blueprint $table) {
            $table->dropForeign(['winning_bid_id']);
        });

        Schema::dropIfExists('old_jewellery_bids');
    }
};
