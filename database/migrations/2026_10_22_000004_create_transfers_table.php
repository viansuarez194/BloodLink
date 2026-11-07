<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();                            // e.g. TRF-260927-0068
            $table->foreignId('from_hospital_id')->constrained('hospitals')->cascadeOnDelete();
            $table->foreignId('to_hospital_id')->constrained('hospitals')->cascadeOnDelete();
            $table->string('blood_type', 3);
            $table->string('component', 20)->default('whole_blood');
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('status', 20)->default('pending');                    // pending | in_transit | completed | cancelled
            $table->dateTime('eta_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->index('from_hospital_id');
            $table->index('to_hospital_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
