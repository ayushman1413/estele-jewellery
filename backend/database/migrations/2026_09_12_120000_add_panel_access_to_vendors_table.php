<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            // The panel login identity for this vendor. Nullable because
            // vendors created before panel access existed (and any vendor the
            // admin adds without an email) keep working through the signed
            // invitation token alone — see Vendor's docblock.
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();

            // Which notifications this contact receives. 'vendor' gets the
            // full bidding stream (invited to bid, outbid, request finalised);
            // 'admin' only ever gets account mail (password setup / resend).
            $table->string('access_role')->default('vendor')->after('is_active');

            $table->index('access_role');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['access_role']);
            $table->dropColumn('access_role');
        });
    }
};
