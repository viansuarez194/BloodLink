<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_drives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');                                               // e.g. "Makati Corporate Donor Drive"
            $table->string('team')->nullable();                                   // e.g. "Team Bravo"
            $table->dateTime('scheduled_at');
            $table->unsignedInteger('verified_donors')->default(0);
            $table->timestamps();

            $table->index(['hospital_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_drives');
    }
};
