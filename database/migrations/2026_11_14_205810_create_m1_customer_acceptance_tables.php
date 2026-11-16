<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bast_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('acceptance_context_ref', 150);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->index(['customer_id', 'created_at']);
        });
        Schema::create('bast_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('bast_documents')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->foreignId('previous_version_id')->nullable()->constrained('bast_versions')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('issued_at', 6)->nullable();
            $table->foreignId('issued_file_id')->nullable()->constrained('private_evidence_files')->restrictOnDelete();
            $table->json('scope_snapshot')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_capacity', 150)->nullable();
            $table->string('authority_ref', 180)->nullable();
            $table->foreignId('decision_file_id')->nullable()->constrained('private_evidence_files')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->unique(['document_id', 'version_no']);
            $table->unique('previous_version_id');
            $table->index(['status', 'issued_at']);
        });
        Schema::create('bast_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('bast_versions')->restrictOnDelete();
            $table->foreignId('report_revision_id')->constrained('service_reports')->restrictOnDelete();
            $table->unsignedInteger('ordinal');
            $table->unique(['version_id', 'report_revision_id']);
            $table->unique(['version_id', 'ordinal']);
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared("CREATE TRIGGER bast_documents_immutable_{$event} BEFORE {$event} ON bast_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BAST_HEADER_IMMUTABLE'");
        }
        DB::unprepared("CREATE TRIGGER bast_versions_immutable_delete BEFORE DELETE ON bast_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BAST_HISTORY_IMMUTABLE'");
        $fields = ['document_id', 'version_no', 'previous_version_id', 'issued_by', 'issued_at', 'issued_file_id', 'scope_snapshot'];
        $changed = implode(' OR ', array_map(fn (string $field): string => "NOT (NEW.{$field} <=> OLD.{$field})", $fields));
        DB::unprepared("CREATE TRIGGER bast_versions_frozen_update BEFORE UPDATE ON bast_versions FOR EACH ROW BEGIN
            IF OLD.status IN ('accepted', 'rejected', 'cancelled', 'superseded') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'BAST_DECISION_IMMUTABLE';
            END IF;
            IF OLD.status <> 'draft' AND ({$changed}) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ISSUED_BAST_IMMUTABLE';
            END IF;
        END");
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $conditions = [];
            if ($event !== 'INSERT') {
                $conditions[] = "EXISTS (SELECT 1 FROM bast_versions WHERE id = OLD.version_id AND status <> 'draft')";
            }
            if ($event !== 'DELETE') {
                $conditions[] = "EXISTS (SELECT 1 FROM bast_versions WHERE id = NEW.version_id AND status <> 'draft')";
            }
            $condition = implode(' OR ', $conditions);
            DB::unprepared("CREATE TRIGGER bast_items_frozen_{$event} BEFORE {$event} ON bast_items FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ISSUED_BAST_ITEMS_IMMUTABLE'; END IF;
            END");
        }
        $accepted = "EXISTS (SELECT 1 FROM bast_items bi JOIN bast_versions bv ON bv.id = bi.version_id JOIN service_reports sr ON sr.id = bi.report_revision_id WHERE sr.scope_item_id = NEW.scope_item_id AND bv.status = 'accepted')";
        DB::unprepared("CREATE TRIGGER service_reports_accepted_scope_insert BEFORE INSERT ON service_reports FOR EACH ROW BEGIN
            IF NEW.scope_item_id IS NOT NULL AND {$accepted} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACCEPTED_SCOPE_IMMUTABLE'; END IF;
        END");
        DB::unprepared("CREATE TRIGGER material_usages_scope_insert BEFORE INSERT ON material_usages FOR EACH ROW BEGIN
            IF {$accepted} OR EXISTS (SELECT 1 FROM work_order_scope_items s JOIN work_orders w ON w.id = s.work_order_id WHERE s.id = NEW.scope_item_id AND w.status IN ('closed', 'cancelled')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACCEPTED_OR_TERMINAL_SCOPE_IMMUTABLE';
            END IF;
        END");
        DB::unprepared("CREATE TRIGGER scope_terminal_insert BEFORE INSERT ON work_order_scope_items FOR EACH ROW BEGIN
            IF EXISTS (SELECT 1 FROM work_orders WHERE id = NEW.work_order_id AND status IN ('closed', 'cancelled')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TERMINAL_WORK_ORDER_SCOPE_IMMUTABLE';
            END IF;
        END");
        $scopeChanged = implode(' OR ', array_map(fn (string $field): string => "NOT (NEW.{$field} <=> OLD.{$field})", ['work_order_id', 'scope_no', 'description', 'acceptance_criteria', 'is_required', 'disposition', 'retired_at', 'retirement_reason']));
        DB::unprepared("CREATE TRIGGER scope_accepted_update BEFORE UPDATE ON work_order_scope_items FOR EACH ROW BEGIN
            IF ({$scopeChanged}) AND (EXISTS (SELECT 1 FROM work_orders WHERE id = OLD.work_order_id AND status IN ('closed', 'cancelled'))
                OR EXISTS (SELECT 1 FROM bast_items bi JOIN bast_versions bv ON bv.id = bi.version_id JOIN service_reports sr ON sr.id = bi.report_revision_id WHERE sr.scope_item_id = OLD.id AND bv.status = 'accepted')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACCEPTED_OR_TERMINAL_SCOPE_IMMUTABLE';
            END IF;
        END");
    }

    public function down(): void
    {
        foreach (['service_reports_accepted_scope_insert', 'material_usages_scope_insert', 'scope_terminal_insert', 'scope_accepted_update'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
        Schema::dropIfExists('bast_items');
        Schema::dropIfExists('bast_versions');
        Schema::dropIfExists('bast_documents');
    }
};
