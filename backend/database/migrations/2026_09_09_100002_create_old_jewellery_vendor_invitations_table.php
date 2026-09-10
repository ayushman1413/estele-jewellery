<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_vendor_invitations', function (Blueprint $table) {
            $table->id();
            // Explicit short constraint names: the auto-generated name
            // ("old_jewellery_vendor_invitations_old_jewellery_request_id_foreign")
            // exceeds MySQL/MariaDB's 64-character identifier limit. SQLite
            // (used in the local/test suite) doesn't enforce this, so the
            // default naming only failed once run against a real MySQL DB.
            $table->foreignId('old_jewellery_request_id');
            $table->foreign('old_jewellery_request_id', 'oj_invitations_request_fk')
                ->references('id')->on('old_jewellery_requests')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->string('response_status')->default('pending');
            $table->text('decline_reason')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['old_jewellery_request_id', 'vendor_id'], 'oj_invitations_request_vendor_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_vendor_invitations');
    }
};
