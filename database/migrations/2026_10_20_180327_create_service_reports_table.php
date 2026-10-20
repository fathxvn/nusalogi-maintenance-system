
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('diagnosis')->nullable();
            $table->text('action_taken')->nullable();
            $table->text('result')->nullable();
            $table->text('recommendation')->nullable();

            $table->string('review_status', 30)
                ->default('draft');

            $table->text('review_notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['work_order_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reports');
    }
};
