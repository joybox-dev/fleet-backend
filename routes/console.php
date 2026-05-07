<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ═══════════════════════════════════════════════════════════════
// FleetOps Scheduled Tasks
// ═══════════════════════════════════════════════════════════════

// Bill all active fixed-monthly contracts on the last day of each month.
// Dispatches SyncFixedContractInvoiceJob per contract → ERPNext Sales Invoice.
Schedule::command('fleetops:invoice-fixed-contracts')
    ->lastDayOfMonth('23:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/fixed-invoicing.log'));
