<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('blood_type', 3);
            $table->unsignedInteger('units')->default(0);
            $table->timestamps();

            $table->unique(['hospital_id', 'blood_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_stocks');
    }
};
