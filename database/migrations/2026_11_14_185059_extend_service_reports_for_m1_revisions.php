<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->foreignId('scope_item_id')
                ->nullable()
                ->constrained('work_order_scope_items')
                ->restrictOnDelete();

            $table->unsignedInteger('revision_no')->nullable();

            $table->foreignId('previous_revision_id')
                ->nullable()
                ->constrained('service_reports')
                ->restrictOnDelete();

            $table->json('checklist_snapshot')->nullable();
            $table->json('usage_snapshot')->nullable();
            $table->json('asset_results')->nullable();

            $table->unsignedInteger('lock_version')->default(0);

            $table->unique(
                ['scope_item_id', 'revision_no'],
                'service_reports_scope_revision_unique',
            );

            $table->unique(
                'previous_revision_id',
                'service_reports_previous_revision_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropForeign(['scope_item_id']);
            $table->dropForeign(['previous_revision_id']);
        });

        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropUnique(
                'service_reports_scope_revision_unique',
            );

            $table->dropUnique(
                'service_reports_previous_revision_unique',
            );
        });

        Schema::table('service_reports', function (Blueprint $table) {
            $table->dropColumn([
                'scope_item_id',
                'revision_no',
                'previous_revision_id',
                'checklist_snapshot',
                'usage_snapshot',
                'asset_results',
                'lock_version',
            ]);
        });
    }
};
