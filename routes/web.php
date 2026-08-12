<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\AssignmentController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\ComparisonReportController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\DestinationCloneController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\DestinationController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\ImportExportController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IncidentBulkController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IncidentController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IngestionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\InterfaceMatrixController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\LogController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\LookupController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\MessageTemplateController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\OverviewController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyActionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyTestController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\RealSimulationController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\SettingsController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\SetupHelperController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\SimulationController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\StatsController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\TemplatePreviewController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware\AuthenticateIngestion;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware\EnsurePluginEnabled;

// Throttle is listed before authentication so an invalid-token flood is rate
// limited before it reaches the DB read + decrypt performed by AuthenticateIngestion.
Route::post('plugin/interface-alert-policy-manager/api/v1/alerts', IngestionController::class)->middleware([EnsurePluginEnabled::class, 'throttle:'.config('iapm.ingestion.rate_limit', '120,1'), AuthenticateIngestion::class])->name('iapm.ingest');
Route::middleware([EnsurePluginEnabled::class, 'web', 'auth', 'can:view iapm'])->prefix('plugin/interface-alert-policy-manager')->name('iapm.')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');
    Route::delete('policies-bulk', [PolicyController::class, 'bulkDestroy'])->name('policies.bulk-destroy');
    Route::resource('policies', PolicyController::class)->except('show');
    Route::post('policies/{policy}/clone', [PolicyController::class, 'clone'])->name('policies.clone');
    Route::get('policies/{policy}/actions/create', [PolicyActionController::class, 'create'])->name('actions.create');
    Route::post('policies/{policy}/actions', [PolicyActionController::class, 'store'])->name('actions.store');
    Route::get('actions/{action}/edit', [PolicyActionController::class, 'edit'])->name('actions.edit');
    Route::put('actions/{action}', [PolicyActionController::class, 'update'])->name('actions.update');
    Route::delete('actions/{action}', [PolicyActionController::class, 'destroy'])->name('actions.destroy');
    Route::post('assignments/preview', [AssignmentController::class, 'preview'])->name('assignments.preview');
    Route::get('devices/search', [AssignmentController::class, 'deviceSearch'])->middleware('can:manage iapm assignments')->name('devices.search');
    // Name-for-id pickers (P1-2). Throttled because they are unauthenticated-shaped
    // search endpoints from the browser's point of view and hit LIKE queries.
    Route::middleware('throttle:60,1')->prefix('lookup')->name('lookup.')->group(function (): void {
        Route::get('devices', [LookupController::class, 'devices'])->name('devices');
        Route::get('ports', [LookupController::class, 'ports'])->name('ports');
        Route::get('incidents', [LookupController::class, 'incidents'])->name('incidents');
        Route::get('users', [LookupController::class, 'users'])->name('users');
    });
    Route::delete('assignments-bulk', [AssignmentController::class, 'bulkDestroy'])->name('assignments.bulk-destroy');
    Route::resource('assignments', AssignmentController::class)->except('show');
    Route::get('interface-matrix', [InterfaceMatrixController::class, 'index'])->name('matrix');
    Route::post('interface-matrix/bulk', [InterfaceMatrixController::class, 'bulk'])->name('matrix.bulk');
    Route::get('interface-matrix/export', [InterfaceMatrixController::class, 'export'])->name('matrix.export');
    Route::post('interface-matrix/rebuild-cache', [InterfaceMatrixController::class, 'rebuildCache'])->middleware('throttle:5,1')->name('matrix.rebuild-cache');
    Route::get('interface-matrix/cache-status', [InterfaceMatrixController::class, 'cacheStatus'])->name('matrix.cache-status');
    Route::get('policy-test', PolicyTestController::class)->name('policy-test');
    Route::get('stats', StatsController::class)->name('stats');
    // The nav labels this page "Statistics & SLA", so /statistics is the obvious
    // guess. Alias it rather than letting a plausible URL dead-end on a 404.
    Route::get('statistics', fn (Request $request) => redirect()->route('iapm.stats', $request->query()))->name('statistics');
    Route::get('tools/simulate', [SimulationController::class, 'form'])->name('simulate');
    Route::post('tools/simulate', [SimulationController::class, 'run'])->name('simulate.run');
    Route::get('tools/real-simulations', [RealSimulationController::class, 'index'])->name('real-simulations.index');
    // Starting is already permission-gated, submission-locked in the UI, and
    // transactionally rejected for unsafe/duplicate ports by the service. An
    // unnamed throttle bucket was shared with other plugin actions and could
    // therefore return a surprise 429 on the user's first simulation attempt.
    Route::post('tools/real-simulations', [RealSimulationController::class, 'store'])->name('real-simulations.store');
    Route::post('tools/real-simulations/{simulation}/recover', [RealSimulationController::class, 'recover'])->name('real-simulations.recover');
    Route::get('export', [ImportExportController::class, 'export'])->name('export');
    Route::get('import', [ImportExportController::class, 'importForm'])->name('import.form');
    Route::post('import', [ImportExportController::class, 'import'])->name('import');
    Route::get('comparison-report', ComparisonReportController::class)->name('comparison-report');
    Route::get('comparison-report/export', [ComparisonReportController::class, 'export'])->name('comparison-report.export');
    Route::get('setup-helper', SetupHelperController::class)->name('setup-helper');
    Route::match(['get', 'post'], 'template-preview', TemplatePreviewController::class)->name('template-preview');
    Route::get('message-templates', [MessageTemplateController::class, 'edit'])->name('message-templates');
    Route::put('message-templates', [MessageTemplateController::class, 'update'])->name('message-templates.update');
    Route::delete('destinations-bulk', [DestinationController::class, 'bulkDestroy'])->name('destinations.bulk-destroy');
    Route::resource('destinations', DestinationController::class)->except('show');
    Route::post('destinations/{destination}/clone', DestinationCloneController::class)->name('destinations.clone');
    Route::post('destinations/{destination}/test', [DestinationController::class, 'test'])->middleware('throttle:5,1')->name('destinations.test');
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::post('incidents/bulk', IncidentBulkController::class)->name('incidents.bulk');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('incidents/{incident}/unacknowledge', [IncidentController::class, 'unacknowledge'])->name('incidents.unacknowledge');
    Route::post('incidents/{incident}/mute', [IncidentController::class, 'mute'])->name('incidents.mute');
    Route::post('incidents/{incident}/unmute', [IncidentController::class, 'unmute'])->name('incidents.unmute');
    Route::post('incidents/{incident}/reconcile', [IncidentController::class, 'reconcile'])->name('incidents.reconcile');
    Route::post('incidents/{incident}/resend', [IncidentController::class, 'resend'])->middleware('throttle:5,1')->name('incidents.resend');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/rotate-token', [SettingsController::class, 'rotateToken'])->name('settings.rotate-token');
    // Fetched on demand rather than rendered into the page, so the token is not
    // in the HTML of every settings/setup-helper view for anyone with `view iapm`.
    Route::get('settings/ingestion-token', [SettingsController::class, 'revealToken'])->middleware('throttle:10,1')->name('settings.reveal-token');
    Route::get('delivery-log', [LogController::class, 'deliveries'])->middleware('can:view iapm audit logs')->name('delivery-log');
    Route::get('delivery-log/export', [LogController::class, 'exportDeliveries'])->middleware('can:view iapm audit logs')->name('delivery-log.export');
    Route::get('audit-log', [LogController::class, 'audits'])->middleware('can:view iapm audit logs')->name('audit-log');
    Route::get('audit-log/export', [LogController::class, 'exportAudits'])->middleware('can:view iapm audit logs')->name('audit-log.export');

    // Must stay last: an unmatched path under the plugin prefix renders the
    // plugin's own 404 so the navigation is still reachable. Registering it as a
    // route rather than only as an exception renderer keeps the `web` middleware
    // stack in play, which the LibreNMS layout expects.
    Route::get('{iapmMissingPath}', fn (Request $request) => response()->view('iapm::errors.404', [
        'requestedPath' => '/'.ltrim($request->path(), '/'),
        'missingResource' => null,
    ], 404))->where('iapmMissingPath', '.*')->name('not-found');
});
