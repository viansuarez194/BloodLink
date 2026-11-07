<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('donor')->index();   // donor | receiver | hospital
            $table->string('mobile', 20)->nullable()->unique();      // stored as 09XXXXXXXXX
            $table->string('blood_type', 3)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('region', 10)->nullable();                // key from config('bloodlink.regions')
            $table->string('organization')->nullable();              // facility name (hospital accounts)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'mobile', 'blood_type', 'date_of_birth', 'sex', 'region', 'organization']);
        });
    }
};
