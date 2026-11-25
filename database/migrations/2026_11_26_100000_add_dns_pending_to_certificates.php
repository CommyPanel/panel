<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Wildcard names without a DNS provider: the TXT records the person has to create
            // ({domains, records: [{name, value}], expires_at}) while the order waits for them.
            $table->json('dns_pending')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('dns_pending');
        });
    }
};
