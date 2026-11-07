<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();                 // e.g. REQ-260924-0187
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // the receiver who filed it
            $table->string('patient_name');
            $table->string('blood_type', 3);
            $table->string('component', 30)->default('whole_blood');
            $table->unsignedSmallInteger('units')->default(1);
            $table->string('clinical_note')->nullable();               // e.g. "Scheduled cardiac surgery · Oct 1, 2026"
            $table->boolean('is_urgent')->default(false);
            $table->dateTime('needed_by')->nullable();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();  // matched facility
            $table->string('status', 20)->default('pending');          // pending | verified | matched | ready | completed | cancelled
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();                                      // created_at = "Request filed"

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_requests');
    }
};
