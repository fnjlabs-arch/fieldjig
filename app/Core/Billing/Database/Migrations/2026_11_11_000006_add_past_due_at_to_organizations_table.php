<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * past_due_at anchors the 7-day past-due grace period (§2 state
 * machine): an org that is still past_due 7 days after this timestamp
 * is moved to canceled by the lazy transition in ResolveTenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations') || Schema::hasColumn('organizations', 'past_due_at')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dateTime('past_due_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'past_due_at')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn('past_due_at');
            });
        }
    }
};
