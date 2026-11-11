<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients') || Schema::hasColumn('clients', 'organization_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            // Nullable: legacy rows are unassigned (NULL = no org).
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'organization_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
