<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('service_tickets')->restrictOnDelete();
            $table->foreignId('evidence_file_id')->constrained('private_evidence_files')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->foreignId('attached_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('attached_at', 6);
            $table->unique(['ticket_id', 'evidence_file_id']);
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared("CREATE TRIGGER ticket_attachments_immutable_{$event} BEFORE {$event} ON ticket_attachments FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TICKET_ATTACHMENT_IMMUTABLE'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
