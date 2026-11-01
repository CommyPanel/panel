<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            // The folder under <data dir>/volumes/<app slug>/ that holds the data.
            $table->string('name', 32);
            // Where the container sees it.
            $table->string('path', 255);
            $table->timestamps();

            $table->unique(['app_id', 'name']);
            $table->unique(['app_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_volumes');
    }
};
