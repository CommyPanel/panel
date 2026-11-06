<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Watch a new release after it goes live and roll back by itself if it is clearly worse.
            $table->boolean('watch_enabled')->default(true);
            $table->unsignedSmallInteger('watch_minutes')->default(5);
            // A gradual rollout: off unless somebody turns it on.
            $table->boolean('rollout_enabled')->default(false);
            $table->json('rollout_steps')->nullable();
            $table->unsignedSmallInteger('rollout_hold_minutes')->default(5);
            $table->boolean('rollout_auto')->default(true);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('live_at')->nullable();
            // The watch after going live: the old container is kept warm until it ends.
            $table->string('watch_state', 16)->nullable();
            $table->timestamp('watch_until')->nullable();
            $table->unsignedBigInteger('watch_previous_id')->nullable();
            $table->unsignedTinyInteger('watch_strikes')->default(0);
            $table->string('watch_note', 300)->nullable();
            // Going live again on purpose after an automatic rollback.
            $table->boolean('retry')->default(false);
            $table->boolean('skip_watch')->default(false);
        });

        Schema::create('rollouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('previous_deployment_id')->nullable();
            $table->json('steps');
            $table->unsignedTinyInteger('step_index')->default(0);
            $table->unsignedTinyInteger('percent');
            $table->unsignedSmallInteger('hold_minutes');
            $table->boolean('auto');
            // running, waiting (needs a person), promoted, aborted, rolled_back
            $table->string('state', 16)->default('running');
            $table->timestamp('step_started_at');
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->string('note', 300)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollouts');

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['live_at', 'watch_state', 'watch_until', 'watch_previous_id', 'watch_strikes', 'watch_note', 'retry', 'skip_watch']);
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn(['watch_enabled', 'watch_minutes', 'rollout_enabled', 'rollout_steps', 'rollout_hold_minutes', 'rollout_auto']);
        });
    }
};
