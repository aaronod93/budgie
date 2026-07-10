<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            // One target per category.
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('type', ['refill_monthly', 'monthly_builder', 'balance_by_date']);
            $table->bigInteger('amount');
            $table->date('target_date')->nullable();
            $table->timestamps();
        });

        Schema::table('payees', function (Blueprint $table) {
            // Auto-categorisation: new transactions for this payee default here.
            $table->foreignId('default_category_id')
                ->nullable()
                ->after('transfer_account_id')
                ->constrained('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_category_id');
        });
        Schema::dropIfExists('targets');
    }
};
