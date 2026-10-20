
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_updates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['work_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_updates');
    }
};
