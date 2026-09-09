<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_jewellery_request_id')->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->string('actor_type'); // customer | vendor | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('old_jewellery_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_activity_logs');
    }
};
