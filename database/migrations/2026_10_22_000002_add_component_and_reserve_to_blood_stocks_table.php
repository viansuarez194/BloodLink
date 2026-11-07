<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_stocks', function (Blueprint $table) {
            $table->string('component', 20)->default('whole_blood');      // whole_blood | platelets | plasma
            $table->unsignedInteger('minimum_reserve')->default(10);      // below this = Low / Critical

            // One row per hospital + blood type + component.
            $table->dropUnique(['hospital_id', 'blood_type']);
            $table->unique(['hospital_id', 'blood_type', 'component']);
        });
    }

    public function down(): void
    {
        Schema::table('blood_stocks', function (Blueprint $table) {
            $table->dropUnique(['hospital_id', 'blood_type', 'component']);
            $table->unique(['hospital_id', 'blood_type']);
            $table->dropColumn(['component', 'minimum_reserve']);
        });
    }
};
