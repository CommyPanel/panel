<?php

use App\Console\Commands\BackupCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('commypanel:check-domains')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('commypanel:tick-safe-deployments')->everyMinute()->withoutOverlapping();

Schedule::command('commypanel:tick-remote-access')->everyMinute()->withoutOverlapping();

Schedule::command('commypanel:renew-certificates')->dailyAt('03:17')->withoutOverlapping();

Schedule::command('commypanel:backup')->dailyAt(BackupCommand::AT)->withoutOverlapping();

Schedule::command('commypanel:backup-databases')->dailyAt('04:00')->withoutOverlapping();

Schedule::command('commypanel:monitor')->everyMinute()->withoutOverlapping();
