<?php

// API routes: audit, access, two-step sign-in, messages and data requests on
// the phone. Inside the authenticated, subscribed, idempotent group of
// routes/api.php. Each route carries the permission of the web page it
// mirrors and calls the same service (docs/api/admin.md, audit.md, security.md).

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\DataRequestController;
use App\Http\Controllers\Api\V1\MessageSettingsController;
use App\Http\Controllers\Api\V1\StaffUserController;
use Illuminate\Support\Facades\Route;

// ── Audit log (manage-settings): the "Records opened" tab, one change, the filter lists ──
Route::get('audit-log/filters', [AuditController::class, 'filters'])->name('api.audit.filters');
Route::get('audit-log/opens', [AuditController::class, 'opens'])->name('api.audit.opens');
Route::get('audit-log/{entry}', [AuditController::class, 'show'])->whereNumber('entry')->name('api.audit.show');

// ── Settings, Staff and access: read with manage-settings, change as a hospital admin ──
Route::get('admin/access', [AccessController::class, 'show'])->name('api.admin.access.show');
Route::put('admin/access/roles/{role}/abilities/{ability}', [AccessController::class, 'set'])
    ->where(['role' => '[a-z_]+', 'ability' => '[a-z_]+'])->name('api.admin.access.set');
Route::post('admin/access/roles/{role}/reset', [AccessController::class, 'reset'])->where('role', '[a-z_]+')->name('api.admin.access.reset');
// One person's extra abilities, on their account (User accounts).
Route::get('admin/users/{user}/abilities', [AccessController::class, 'personal'])->whereNumber('user')->name('api.admin.users.abilities.show');
Route::put('admin/users/{user}/abilities', [AccessController::class, 'setPersonal'])->whereNumber('user')->name('api.admin.users.abilities.update');

// ── Two-step sign-in ──
// A colleague who lost their phone (whoever may edit the account; never your own).
Route::post('admin/users/{user}/reset-two-factor', [StaffUserController::class, 'resetTwoFactor'])->whereNumber('user')->name('api.admin.users.reset-two-factor');
// Your own, behind your password; a few tries a minute, in a bucket of their own.
Route::post('auth/two-factor/disable', [AccountSecurityController::class, 'disable'])->middleware('throttle:12,1,two-factor-disable')->name('api.auth.two-factor.disable');
Route::post('auth/two-factor/recovery-codes', [AccountSecurityController::class, 'regenerate'])->middleware('throttle:12,1,two-factor-codes')->name('api.auth.two-factor.recovery-codes');

// ── Settings, Messages to patients (manage-settings) ──
Route::get('admin/settings/messages', [MessageSettingsController::class, 'show'])->name('api.admin.settings.messages.show');
Route::match(['put', 'patch'], 'admin/settings/messages', [MessageSettingsController::class, 'update'])->name('api.admin.settings.messages.update');
Route::get('admin/messages', [MessageSettingsController::class, 'log'])->name('api.admin.messages.index');

// ── Data requests (manage-settings, one clinic's log) ──
Route::get('data-requests', [DataRequestController::class, 'index'])->name('api.data-requests.index');
Route::post('data-requests', [DataRequestController::class, 'store'])->name('api.data-requests.store');
Route::get('data-requests/{dataRequest:uuid}', [DataRequestController::class, 'show'])->name('api.data-requests.show');
Route::post('data-requests/{dataRequest:uuid}/answer', [DataRequestController::class, 'answer'])->name('api.data-requests.answer');
Route::post('data-requests/{dataRequest:uuid}/refuse', [DataRequestController::class, 'refuse'])->name('api.data-requests.refuse');
// A patient's data for a request to see it: the web's own PDF, behind the same checks.
Route::get('patients/{patient}/personal-data.{format}', [\App\Http\Controllers\Admin\DataProtectionController::class, 'patient'])
    ->where('format', 'pdf')->name('api.patients.personal-data');
