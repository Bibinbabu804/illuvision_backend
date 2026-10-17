<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_method');
            }

            if (!Schema::hasColumn('payments', 'payment_proof')) {
                $table->string('payment_proof')->nullable()->after('payment_reference');
            }

            if (!Schema::hasColumn('payments', 'verified_by_admin_id')) {
                $table->foreignId('verified_by_admin_id')
                    ->nullable()
                    ->after('payment_proof')
                    ->constrained('users')
                    ->nullOnDelete();

                $table->index('verified_by_admin_id');
            }

            if (!Schema::hasColumn('payments', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by_admin_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'verified_by_admin_id')) {
                $table->dropForeign(['verified_by_admin_id']);
                $table->dropColumn('verified_by_admin_id');
            }

            $table->dropColumn(array_filter([
                Schema::hasColumn('payments', 'payment_reference') ? 'payment_reference' : null,
                Schema::hasColumn('payments', 'payment_proof') ? 'payment_proof' : null,
                Schema::hasColumn('payments', 'verified_at') ? 'verified_at' : null,
            ]));
        });
    }
};
