<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_number', 50)->unique();
            $table->string('kind', 24);
            $table->string('channel', 30);
            $table->string('contact_label', 150);
            $table->dateTime('received_at', 6);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('customer_sites')->restrictOnDelete();
            $table->foreignId('primary_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->text('description');
            $table->string('priority', 20)->nullable();
            $table->string('status', 30)->default('new');
            $table->string('authorization_ref', 180)->nullable();
            $table->string('coverage_disposition', 24)->nullable();
            $table->text('resolution_note')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('service_tickets')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('created_at', 6);
            $table->index(['status', 'received_at']);
            $table->index(['customer_id', 'received_at']);
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->string('origin_kind', 20)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('service_tickets')->restrictOnDelete();
            $table->text('manual_reason')->nullable();
            $table->string('acceptance_context_ref', 150)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->index(['ticket_id', 'status']);
            $table->index(['customer_site_id', 'status']);
        });
        Schema::create('work_order_scope_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->unsignedInteger('scope_no');
            $table->text('description');
            $table->text('acceptance_criteria');
            $table->boolean('is_required')->default(true);
            $table->string('disposition', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->dateTime('retired_at', 6)->nullable();
            $table->text('retirement_reason')->nullable();
            $table->unique(['work_order_id', 'scope_no']);
        });
        Schema::create('operation_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('command_key', 36);
            $table->string('action', 64);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30)->nullable();
            $table->string('actor_role', 32);
            $table->dateTime('occurred_at', 6);
            $table->text('reason')->nullable();
            $table->char('request_digest', 64);
            $table->json('facts');
            $table->unique(['actor_id', 'command_key']);
            $table->index(['subject_type', 'subject_id', 'occurred_at', 'id'], 'operation_history_subject_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('work_orders', ['customer_site_id'])) {
            Schema::table('work_orders', function (Blueprint $table): void {
                $table->index(
                    'customer_site_id',
                    'work_orders_customer_site_id_foreign'
                );
            });
        }

        Schema::dropIfExists('operation_history');
        Schema::dropIfExists('work_order_scope_items');

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropForeign(['ticket_id']);
            $table->dropIndex(['ticket_id', 'status']);
            $table->dropIndex(['customer_site_id', 'status']);
            $table->dropColumn([
                'origin_kind',
                'ticket_id',
                'manual_reason',
                'acceptance_context_ref',
                'lock_version',
            ]);
        });

        Schema::dropIfExists('service_tickets');
    }
};
