<?php

use Illuminate\Support\Facades\Route;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IngestionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\OverviewController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware\AuthenticateIngestion;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware\EnsurePluginEnabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\AssignmentController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\DestinationController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IncidentController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\SettingsController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyActionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\LogController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\InterfaceMatrixController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\ScheduleController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\PolicyTestController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\ComparisonReportController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\SetupHelperController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\TemplatePreviewController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IncidentBulkController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\DestinationCloneController;

// Throttle is listed before authentication so an invalid-token flood is rate
// limited before it reaches the DB read + decrypt performed by AuthenticateIngestion.
Route::post('plugin/interface-alert-policy-manager/api/v1/alerts', IngestionController::class)->middleware([EnsurePluginEnabled::class, 'throttle:'.config('iapm.ingestion.rate_limit', '120,1'), AuthenticateIngestion::class])->name('iapm.ingest');
Route::middleware([EnsurePluginEnabled::class, 'web', 'auth', 'can:view iapm'])->prefix('plugin/interface-alert-policy-manager')->name('iapm.')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');
    Route::delete('policies-bulk', [PolicyController::class, 'bulkDestroy'])->name('policies.bulk-destroy');
    Route::resource('policies', PolicyController::class)->except('show'); Route::post('policies/{policy}/clone', [PolicyController::class, 'clone'])->name('policies.clone');
    Route::get('policies/{policy}/actions/create', [PolicyActionController::class, 'create'])->name('actions.create'); Route::post('policies/{policy}/actions', [PolicyActionController::class, 'store'])->name('actions.store'); Route::get('actions/{action}/edit', [PolicyActionController::class, 'edit'])->name('actions.edit'); Route::put('actions/{action}', [PolicyActionController::class, 'update'])->name('actions.update'); Route::delete('actions/{action}', [PolicyActionController::class, 'destroy'])->name('actions.destroy');
    Route::post('assignments/preview', [AssignmentController::class, 'preview'])->name('assignments.preview');
    Route::delete('assignments-bulk', [AssignmentController::class, 'bulkDestroy'])->name('assignments.bulk-destroy');
    Route::resource('assignments', AssignmentController::class)->except('show');
    Route::get('interface-matrix', [InterfaceMatrixController::class, 'index'])->name('matrix'); Route::post('interface-matrix/bulk', [InterfaceMatrixController::class, 'bulk'])->name('matrix.bulk'); Route::get('interface-matrix/export', [InterfaceMatrixController::class, 'export'])->name('matrix.export');
    Route::get('policy-test', PolicyTestController::class)->name('policy-test');
    Route::get('comparison-report', ComparisonReportController::class)->name('comparison-report');
    Route::get('setup-helper', SetupHelperController::class)->name('setup-helper');
    Route::match(['get','post'], 'template-preview', TemplatePreviewController::class)->name('template-preview');
    Route::delete('schedules-bulk', [ScheduleController::class, 'bulkDestroy'])->name('schedules.bulk-destroy');
    Route::resource('schedules', ScheduleController::class)->except('show');
    Route::delete('destinations-bulk', [DestinationController::class, 'bulkDestroy'])->name('destinations.bulk-destroy');
    Route::resource('destinations', DestinationController::class)->except('show'); Route::post('destinations/{destination}/clone', DestinationCloneController::class)->name('destinations.clone'); Route::post('destinations/{destination}/test', [DestinationController::class, 'test'])->middleware('throttle:5,1')->name('destinations.test');
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index'); Route::post('incidents/bulk', IncidentBulkController::class)->name('incidents.bulk'); Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show'); Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge'); Route::post('incidents/{incident}/unacknowledge', [IncidentController::class, 'unacknowledge'])->name('incidents.unacknowledge'); Route::post('incidents/{incident}/mute', [IncidentController::class, 'mute'])->name('incidents.mute'); Route::post('incidents/{incident}/unmute', [IncidentController::class, 'unmute'])->name('incidents.unmute'); Route::post('incidents/{incident}/reconcile', [IncidentController::class, 'reconcile'])->name('incidents.reconcile'); Route::post('incidents/{incident}/resend', [IncidentController::class, 'resend'])->middleware('throttle:5,1')->name('incidents.resend');
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit'); Route::put('settings', [SettingsController::class, 'update'])->name('settings.update'); Route::post('settings/rotate-token', [SettingsController::class, 'rotateToken'])->name('settings.rotate-token');
    Route::get('delivery-log', [LogController::class, 'deliveries'])->middleware('can:view iapm audit logs')->name('delivery-log');
    Route::get('audit-log', [LogController::class, 'audits'])->middleware('can:view iapm audit logs')->name('audit-log');
});
