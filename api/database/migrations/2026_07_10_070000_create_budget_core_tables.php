<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('currency', 3)->default('AUD');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['checking', 'savings', 'cash', 'credit', 'tracking']);
            $table->boolean('on_budget')->default(true);
            $table->boolean('closed')->default(false);
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::create('category_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('hidden')->default(false);
            // Internal groups hold system categories (e.g. Ready to Assign) and
            // never render as normal envelope groups.
            $table->boolean('internal')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('hidden')->default(false);
            // 'ready_to_assign' marks the single system category income flows into.
            // Phase 2 adds 'credit_card_payment' with linked_account_id set.
            $table->string('internal_type')->nullable();
            $table->foreignId('linked_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'sort_order']);
        });

        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Set when this payee represents "Transfer : <account>".
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'name']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Minor units (cents); outflows negative, inflows positive.
            $table->bigInteger('amount');
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->text('memo')->nullable();
            $table->enum('cleared', ['uncleared', 'cleared', 'reconciled'])->default('uncleared');
            $table->boolean('approved')->default(true);
            // The mirrored row on the other account of a transfer.
            $table->foreignId('transfer_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('import_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['budget_id', 'date']);
            $table->index(['account_id', 'date']);
            $table->index(['category_id', 'date']);
            $table->unique(['account_id', 'import_id']);
        });

        Schema::create('sub_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->text('memo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('monthly_budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            // First day of the month this row assigns money in.
            $table->date('month');
            $table->bigInteger('assigned')->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'month', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_budgets');
        Schema::dropIfExists('sub_transactions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payees');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_groups');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('budgets');
    }
};
