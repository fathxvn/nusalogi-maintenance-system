<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_evidence_files', function (Blueprint $table): void {
            $table->id();
            $table->string('storage_disk', 32);
            $table->string('storage_key', 255);
            $table->string('original_name', 255);
            $table->string('media_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at', 6);
            $table->string('state', 16)->default('staged');
            $table->unique(['storage_disk', 'storage_key']);
            $table->index(['state', 'uploaded_at']);
        });
        Schema::table('service_photos', function (Blueprint $table): void {
            $table->foreignId('evidence_file_id')->nullable()->constrained('private_evidence_files')->restrictOnDelete();
            $table->unique(['service_report_id', 'evidence_file_id']);
        });
        Schema::create('material_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scope_item_id')->constrained('work_order_scope_items')->restrictOnDelete();
            $table->string('item_description', 180);
            $table->string('unit_code', 24);
            $table->decimal('quantity', 15, 3);
            $table->string('movement_kind', 16);
            $table->foreignId('adjusts_usage_id')->nullable()->constrained('material_usages')->restrictOnDelete();
            $table->string('supply_source', 24);
            $table->string('disposition', 24);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->text('reason')->nullable();
            $table->index(['scope_item_id', 'recorded_at']);
        });
        DB::statement('ALTER TABLE material_usages ADD CONSTRAINT material_usages_quantity_positive CHECK (quantity > 0)');
        DB::statement("ALTER TABLE material_usages ADD CONSTRAINT material_usages_parent_kind CHECK ((movement_kind = 'consume' AND adjusts_usage_id IS NULL) OR (movement_kind IN ('return', 'reversal') AND adjusts_usage_id IS NOT NULL))");

        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared("CREATE TRIGGER material_usages_immutable_{$event} BEFORE {$event} ON material_usages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'MATERIAL_MOVEMENT_IMMUTABLE'");
        }

        $fields = ['work_order_id', 'scope_item_id', 'revision_no', 'previous_revision_id', 'created_by',
            'diagnosis', 'action_taken', 'result', 'recommendation', 'checklist_snapshot', 'usage_snapshot', 'asset_results', 'submitted_at'];
        $changed = implode(' OR ', array_map(fn (string $field): string => "NOT (NEW.{$field} <=> OLD.{$field})", $fields));
        DB::unprepared("CREATE TRIGGER service_reports_frozen_update BEFORE UPDATE ON service_reports FOR EACH ROW
            BEGIN
                IF OLD.scope_item_id IS NOT NULL AND OLD.submitted_at IS NOT NULL AND ({$changed}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SUBMITTED_REPORT_IMMUTABLE';
                END IF;
                IF OLD.scope_item_id IS NOT NULL AND OLD.submitted_at IS NOT NULL AND OLD.review_status IN ('approved', 'needs_revision')
                    AND (NOT (NEW.review_status <=> OLD.review_status) OR NOT (NEW.reviewed_by <=> OLD.reviewed_by)
                    OR NOT (NEW.reviewed_at <=> OLD.reviewed_at) OR NOT (NEW.review_notes <=> OLD.review_notes)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REPORT_REVIEW_IMMUTABLE';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER service_reports_frozen_delete BEFORE DELETE ON service_reports FOR EACH ROW
            BEGIN
                IF OLD.scope_item_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'M1_REPORT_HISTORY_IMMUTABLE';
                END IF;
            END");
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $conditions = [];
            if ($event !== 'INSERT') {
                $conditions[] = 'EXISTS (SELECT 1 FROM service_reports WHERE id = OLD.service_report_id AND scope_item_id IS NOT NULL AND submitted_at IS NOT NULL)';
            }
            if ($event !== 'DELETE') {
                $conditions[] = 'EXISTS (SELECT 1 FROM service_reports WHERE id = NEW.service_report_id AND scope_item_id IS NOT NULL AND submitted_at IS NOT NULL)';
            }
            $condition = implode(' OR ', $conditions);
            DB::unprepared("CREATE TRIGGER service_photos_frozen_{$event} BEFORE {$event} ON service_photos FOR EACH ROW
                BEGIN
                    IF {$condition} THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SUBMITTED_EVIDENCE_IMMUTABLE';
                    END IF;
                END");
        }
        DB::unprepared("CREATE TRIGGER private_evidence_files_immutable_update BEFORE UPDATE ON private_evidence_files FOR EACH ROW
            BEGIN
                IF NOT (NEW.storage_disk <=> OLD.storage_disk) OR NOT (NEW.storage_key <=> OLD.storage_key)
                    OR NOT (NEW.original_name <=> OLD.original_name) OR NOT (NEW.media_type <=> OLD.media_type)
                    OR NOT (NEW.size_bytes <=> OLD.size_bytes) OR NOT (NEW.sha256 <=> OLD.sha256)
                    OR NOT (NEW.uploaded_by <=> OLD.uploaded_by) OR NOT (NEW.uploaded_at <=> OLD.uploaded_at)
                    OR (OLD.state = 'quarantined' AND NEW.state <> 'quarantined')
                    OR (OLD.state = 'ready' AND NEW.state NOT IN ('ready', 'quarantined')) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EVIDENCE_METADATA_IMMUTABLE';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER private_evidence_files_immutable_delete BEFORE DELETE ON private_evidence_files FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EVIDENCE_METADATA_IMMUTABLE'");
    }

    public function down(): void
    {
        foreach (['service_reports_frozen_update', 'service_reports_frozen_delete', 'private_evidence_files_immutable_update', 'private_evidence_files_immutable_delete'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS service_photos_frozen_{$event}");
        }
        Schema::dropIfExists('material_usages');
        Schema::table('service_photos', function (Blueprint $table): void {
            $table->dropUnique(['service_report_id', 'evidence_file_id']);
            $table->dropConstrainedForeignId('evidence_file_id');
        });
        Schema::dropIfExists('private_evidence_files');
    }
};
