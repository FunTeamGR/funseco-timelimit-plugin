<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FlexiTime per-track limits (/timeset); XAseco's custom_tracktimes, one row per map.
        Schema::create('timelimit_custom_times', function (Blueprint $table) {
            $table->id();
            $table->string('map_uid')->unique();
            $table->unsignedInteger('seconds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timelimit_custom_times');
    }
};
