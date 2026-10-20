
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_report_id')
                ->constrained('service_reports')
                ->restrictOnDelete();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('phase', 20);

            $table->string('storage_path');

            $table->text('caption')->nullable();

            $table->timestamps();

            $table->index(['service_report_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_photos');
    }
};
