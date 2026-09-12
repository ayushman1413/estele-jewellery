<?php

use App\Services\OldJewellery\OldJewelleryRequestService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renumbers old jewellery requests from OJ-{year}-{6 digits} to a plain
 * zero-padded sequence (001, 002, ...).
 *
 * Two things have to change together:
 *  - the counter stops being per-year (the number no longer carries a year,
 *    so a yearly reset would re-issue numbers that already exist), and
 *  - existing rows are renumbered, because request_number is the route key
 *    and a mixed format would be visible to customers.
 *
 * Renumbering follows created_at order, so the oldest request becomes 001.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 'year' now holds a sequence key, not a year — varchar(4) is too
        // narrow for it. Renamed in the same breath so the column name stops
        // lying about its contents.
        Schema::table('old_jewellery_number_sequences', function (Blueprint $table) {
            $table->string('year', 32)->change();
        });

        Schema::table('old_jewellery_number_sequences', function (Blueprint $table) {
            $table->renameColumn('year', 'sequence_key');
        });

        DB::transaction(function () {
            $requests = DB::table('old_jewellery_requests')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id']);

            // Two passes with a temporary prefix: request_number is UNIQUE, so
            // assigning "001" directly can collide with a row that still holds
            // an old number mid-loop.
            foreach ($requests as $index => $request) {
                DB::table('old_jewellery_requests')
                    ->where('id', $request->id)
                    ->update(['request_number' => 'tmp-'.($index + 1)]);
            }

            foreach ($requests as $index => $request) {
                DB::table('old_jewellery_requests')
                    ->where('id', $request->id)
                    ->update(['request_number' => sprintf('%03d', $index + 1)]);
            }

            // Point the counter past the highest number just assigned, so the
            // next new request continues the run instead of repeating one.
            DB::table('old_jewellery_number_sequences')->delete();
            DB::table('old_jewellery_number_sequences')->insert([
                'sequence_key' => OldJewelleryRequestService::SEQUENCE_KEY,
                'last_value' => $requests->count(),
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $requests = DB::table('old_jewellery_requests')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'created_at']);

            foreach ($requests as $index => $request) {
                DB::table('old_jewellery_requests')
                    ->where('id', $request->id)
                    ->update(['request_number' => 'tmp-'.($index + 1)]);
            }

            $perYear = [];

            foreach ($requests as $request) {
                $year = date('Y', strtotime($request->created_at));
                $perYear[$year] = ($perYear[$year] ?? 0) + 1;

                DB::table('old_jewellery_requests')
                    ->where('id', $request->id)
                    ->update(['request_number' => sprintf('OJ-%s-%06d', $year, $perYear[$year])]);
            }

            DB::table('old_jewellery_number_sequences')->delete();

            foreach ($perYear as $year => $last) {
                DB::table('old_jewellery_number_sequences')->insert([
                    'sequence_key' => $year,
                    'last_value' => $last,
                ]);
            }
        });

        Schema::table('old_jewellery_number_sequences', function (Blueprint $table) {
            $table->renameColumn('sequence_key', 'year');
        });

        Schema::table('old_jewellery_number_sequences', function (Blueprint $table) {
            $table->string('year', 4)->change();
        });
    }
};
