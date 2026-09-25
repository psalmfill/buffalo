<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zones a Deputy Director General is restricted to. Empty means national.
 * Only valid for DDG users; enforced in App\Actions\Users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('geopolitical_zone_id')->index()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'geopolitical_zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_zones');
    }
};
