<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 16 utf8mb4 characters comfortably fits any emoji ZWJ sequence.
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon', 16)->nullable()->after('name');
        });
        Schema::table('payees', function (Blueprint $table) {
            $table->string('icon', 16)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('icon'));
        Schema::table('payees', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
