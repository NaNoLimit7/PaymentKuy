<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Background Tasks (PRD Section 10)
|--------------------------------------------------------------------------
*/

Schedule::command('transactions:expire')
    ->everyMinute();

Schedule::command('idempotency:purge')
    ->daily();

Schedule::command('blockchain:submit')
    ->everyMinute()
    ->withoutOverlapping();
