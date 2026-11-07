<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();   // the facility sending the blood
            $table->foreignId('blood_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('destination_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->string('destination_name')->nullable();                       // when the destination is not in the hospitals table
            $table->string('blood_type', 3);
            $table->string('component', 20)->default('whole_blood');
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('vehicle')->nullable();                                // e.g. "BL-AMB 04"
            $table->string('team')->nullable();                                   // e.g. "Team Rivera"
            $table->string('status', 20)->default('preparing');                   // preparing | en_route | delivered | cancelled
            $table->dateTime('eta_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->decimal('cold_chain_temp', 3, 1)->nullable();                 // degrees Celsius
            $table->timestamps();

            $table->index(['hospital_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatches');
    }
};
