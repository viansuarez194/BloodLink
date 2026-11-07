<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('purpose')->nullable();            // e.g. "Blood release consultation"
            $table->dateTime('ends_at')->nullable();
            $table->string('location_detail')->nullable();    // e.g. "PGH Blood Bank, 2nd Floor"
            $table->text('notes')->nullable();                // e.g. "Bring valid ID and physician request."
            $table->dateTime('reserved_until')->nullable();   // slot is held until this time
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'ends_at', 'location_detail', 'notes', 'reserved_until', 'confirmed_at']);
        });
    }
};
