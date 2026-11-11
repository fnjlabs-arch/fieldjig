<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('organizations')) {
            return;
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('trial');
            $table->string('status')->default('trialing');
            $table->dateTime('trial_ends_at')->nullable();
            $table->string('billing_provider')->nullable();
            $table->string('billing_customer_id')->nullable();
            $table->string('billing_subscription_id')->nullable();
            $table->unsignedInteger('seats')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
