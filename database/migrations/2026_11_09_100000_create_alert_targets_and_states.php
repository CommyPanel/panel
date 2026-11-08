<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('kind', 16);
            // Encrypted: the secret of the channel (a webhook address, a topic or a bot token).
            $table->text('address');
            $table->string('chat_id', 40)->nullable();
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });

        // Where a thing was when it was last looked at, so messages are sent when it changes.
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 120)->unique();
            $table->string('state', 24)->default('ok');
            $table->timestamp('since')->nullable();
            $table->unsignedInteger('strikes')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->boolean('alerts_enabled')->default(true);
        });

        $this->migrateOldSetting();
    }

    /**
     * The single webhook of earlier versions (setting `alerts.webhook`) becomes one target.
     * Its address is copied as it is: it was written with Crypt::encryptString, which is what
     * the `encrypted` cast reads.
     */
    public function migrateOldSetting(): void
    {
        $raw = DB::table('settings')->where('key', 'alerts.webhook')->value('value');
        $old = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($old) && is_string($old['url'] ?? null) && $old['url'] !== '') {
            $kind = in_array($old['format'] ?? '', ['slack', 'discord', 'json'], true) ? $old['format'] : 'slack';
            $events = ['deployment.failed', 'deployment.rolled_back'];

            if (($old['events'] ?? '') === 'all') {
                $events[] = 'deployment.succeeded';
            }

            DB::table('alert_targets')->insert([
                'name' => 'Default',
                'kind' => $kind,
                'address' => $old['url'],
                'chat_id' => null,
                'events' => json_encode($events),
                'enabled' => (bool) ($old['enabled'] ?? false),
                'last_ok_at' => isset($old['last_ok_at']) ? date('Y-m-d H:i:s', (int) strtotime((string) $old['last_ok_at'])) : null,
                'last_error' => isset($old['last_error']) ? mb_substr((string) $old['last_error'], 0, 300) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->where('key', 'alerts.webhook')->delete();
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('alerts_enabled');
        });
        Schema::dropIfExists('alert_states');
        Schema::dropIfExists('alert_targets');
    }
};
