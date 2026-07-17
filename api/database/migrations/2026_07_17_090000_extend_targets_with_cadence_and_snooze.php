<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            // How often the amount is needed (refill/builder types).
            $table->string('cadence', 10)->default('month')->after('amount');
            // Recurrence window: starts_on anchors week/fortnight cycles and
            // quarter/year spreads; ends_on or repeat_times bound the run.
            $table->date('starts_on')->nullable()->after('target_date');
            $table->date('ends_on')->nullable()->after('starts_on');
            $table->unsignedInteger('repeat_times')->nullable()->after('ends_on');
            // Snoozing: explicit months (["2026-07", ...]) and/or a date; both
            // silence the target's underfunded amount for matching months.
            $table->json('snoozed_months')->nullable()->after('repeat_times');
            $table->date('snoozed_until')->nullable()->after('snoozed_months');
        });
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn([
                'cadence', 'starts_on', 'ends_on', 'repeat_times',
                'snoozed_months', 'snoozed_until',
            ]);
        });
    }
};
