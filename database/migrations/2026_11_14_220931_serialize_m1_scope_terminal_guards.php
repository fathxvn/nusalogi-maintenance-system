<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_terminal_insert');
        DB::unprepared("CREATE TRIGGER scope_terminal_insert BEFORE INSERT ON work_order_scope_items FOR EACH ROW BEGIN
            DECLARE parent_status VARCHAR(30);
            SELECT status INTO parent_status FROM work_orders WHERE id = NEW.work_order_id FOR UPDATE;
            IF parent_status IN ('closed', 'cancelled') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TERMINAL_WORK_ORDER_SCOPE_IMMUTABLE';
            END IF;
        END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_terminal_insert');
        DB::unprepared("CREATE TRIGGER scope_terminal_insert BEFORE INSERT ON work_order_scope_items FOR EACH ROW BEGIN
            IF EXISTS (SELECT 1 FROM work_orders WHERE id = NEW.work_order_id AND status IN ('closed', 'cancelled')) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TERMINAL_WORK_ORDER_SCOPE_IMMUTABLE';
            END IF;
        END");
    }
};
