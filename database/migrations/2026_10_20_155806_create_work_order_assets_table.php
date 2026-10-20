
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_assets', function (Blueprint $table) {

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->foreignId('asset_id')
                ->constrained('assets')
                ->restrictOnDelete();

            $table->primary([
                'work_order_id',
                'asset_id',
            ]);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_assets');
    }
};
