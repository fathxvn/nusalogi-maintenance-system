<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_accepted_update');
        $changed = $this->changed();
        DB::unprepared("CREATE TRIGGER scope_accepted_update BEFORE UPDATE ON work_order_scope_items FOR EACH ROW BEGIN
            DECLARE parent_status VARCHAR(30);
            DECLARE accepted_item BIGINT UNSIGNED DEFAULT NULL;
            IF {$changed} THEN
                SELECT status INTO parent_status FROM work_orders WHERE id = OLD.work_order_id FOR UPDATE;
                SELECT bi.id INTO accepted_item FROM bast_items bi JOIN bast_versions bv ON bv.id = bi.version_id
                    JOIN service_reports sr ON sr.id = bi.report_revision_id WHERE sr.scope_item_id = OLD.id AND bv.status = 'accepted' LIMIT 1 FOR UPDATE;
                IF parent_status IN ('closed', 'cancelled') OR accepted_item IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACCEPTED_OR_TERMINAL_SCOPE_IMMUTABLE';
                END IF;
            END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_accepted_update');
        $changed = $this->changed();
        DB::unprepared("CREATE TRIGGER scope_accepted_update BEFORE UPDATE ON work_order_scope_items FOR EACH ROW BEGIN
            IF ({$changed}) AND (EXISTS (SELECT 1 FROM work_orders WHERE id = OLD.work_order_id AND status IN ('closed', 'cancelled'))
                OR EXISTS (SELECT 1 FROM bast_items bi JOIN bast_versions bv ON bv.id = bi.version_id JOIN service_reports sr ON sr.id = bi.report_revision_id WHERE sr.scope_item_id = OLD.id AND bv.status = 'accepted')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACCEPTED_OR_TERMINAL_SCOPE_IMMUTABLE';
            END IF;
        END");
    }

    private function changed(): string
    {
        return implode(' OR ', array_map(fn (string $field): string => "NOT (NEW.{$field} <=> OLD.{$field})",
            ['work_order_id', 'scope_no', 'description', 'acceptance_criteria', 'is_required', 'disposition', 'retired_at', 'retirement_reason', 'created_by', 'created_at']));
    }
};
