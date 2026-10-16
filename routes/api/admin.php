<?php

// API routes: administration, settings and account state. Inside the
// authenticated, subscribed group of routes/api.php, so every route here is
// /api/v1/admin/… behind auth:sanctum + api.account + subscribed. Each carries
// the same permission as the web page it mirrors (see docs/api/admin.md).

use App\Http\Controllers\Api\V1\BedController;
use App\Http\Controllers\Api\V1\BillingSettingsController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HospitalSettingsController;
use App\Http\Controllers\Api\V1\LicenceController;
use App\Http\Controllers\Api\V1\OfflineDeviceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PracticeSettingsController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\StaffProfileController;
use App\Http\Controllers\Api\V1\StaffUserController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\WardController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    // ── Hospital structure ─────────────────────────────────────
    Route::get('departments', [DepartmentController::class, 'index'])->name('api.admin.departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('api.admin.departments.store');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('api.admin.departments.show');
    Route::match(['put', 'patch'], 'departments/{department}', [DepartmentController::class, 'update'])->name('api.admin.departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->name('api.admin.departments.destroy');

    Route::get('rooms/options', [RoomController::class, 'options'])->name('api.admin.rooms.options');
    Route::get('rooms', [RoomController::class, 'index'])->name('api.admin.rooms.index');
    Route::post('rooms', [RoomController::class, 'store'])->name('api.admin.rooms.store');
    Route::get('rooms/{room}', [RoomController::class, 'show'])->name('api.admin.rooms.show');
    Route::match(['put', 'patch'], 'rooms/{room}', [RoomController::class, 'update'])->name('api.admin.rooms.update');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name('api.admin.rooms.destroy');

    Route::get('wards', [WardController::class, 'index'])->name('api.admin.wards.index');
    Route::post('wards', [WardController::class, 'store'])->name('api.admin.wards.store');
    Route::get('wards/{ward}', [WardController::class, 'show'])->name('api.admin.wards.show');
    Route::get('wards/{ward}/reprice-impact', [WardController::class, 'repriceImpact'])->name('api.admin.wards.reprice-impact');
    Route::match(['put', 'patch'], 'wards/{ward}', [WardController::class, 'update'])->name('api.admin.wards.update');
    Route::delete('wards/{ward}', [WardController::class, 'destroy'])->name('api.admin.wards.destroy');

    Route::get('beds/options', [BedController::class, 'options'])->name('api.admin.beds.options');
    Route::get('beds', [BedController::class, 'index'])->name('api.admin.beds.index');
    Route::post('beds', [BedController::class, 'store'])->name('api.admin.beds.store');
    Route::get('beds/{bed}', [BedController::class, 'show'])->name('api.admin.beds.show');
    Route::match(['put', 'patch'], 'beds/{bed}', [BedController::class, 'update'])->name('api.admin.beds.update');
    Route::post('beds/{bed}/status', [BedController::class, 'status'])->name('api.admin.beds.status');
    Route::delete('beds/{bed}', [BedController::class, 'destroy'])->name('api.admin.beds.destroy');

    // ── People ─────────────────────────────────────────────────
    Route::get('staff-profiles', [StaffProfileController::class, 'index'])->name('api.admin.staff-profiles.index');
    Route::post('staff-profiles', [StaffProfileController::class, 'store'])->name('api.admin.staff-profiles.store');
    Route::get('staff-profiles/{staffProfile}', [StaffProfileController::class, 'show'])->name('api.admin.staff-profiles.show');
    Route::match(['put', 'patch'], 'staff-profiles/{staffProfile}', [StaffProfileController::class, 'update'])->name('api.admin.staff-profiles.update');
    Route::delete('staff-profiles/{staffProfile}', [StaffProfileController::class, 'destroy'])->name('api.admin.staff-profiles.destroy');

    Route::get('roles', [StaffUserController::class, 'roles'])->name('api.admin.roles.index');
    Route::get('users', [StaffUserController::class, 'index'])->name('api.admin.users.index');
    Route::post('users', [StaffUserController::class, 'store'])->name('api.admin.users.store');
    Route::get('users/{user}', [StaffUserController::class, 'show'])->whereNumber('user')->name('api.admin.users.show');
    // POST is accepted for the update too: an avatar goes up as multipart, which PHP only parses on POST.
    Route::match(['put', 'patch', 'post'], 'users/{user}', [StaffUserController::class, 'update'])->whereNumber('user')->name('api.admin.users.update');
    Route::post('users/{user}/activate', [StaffUserController::class, 'activate'])->whereNumber('user')->name('api.admin.users.activate');
    Route::post('users/{user}/deactivate', [StaffUserController::class, 'deactivate'])->whereNumber('user')->name('api.admin.users.deactivate');
    Route::post('users/{user}/reset-password', [StaffUserController::class, 'resetPassword'])->whereNumber('user')->name('api.admin.users.reset-password');
    Route::delete('users/{user}', [StaffUserController::class, 'destroy'])->whereNumber('user')->name('api.admin.users.destroy');

    // ── Offline ────────────────────────────────────────────────
    Route::get('offline-devices', [OfflineDeviceController::class, 'index'])->name('api.admin.offline-devices.index');
    Route::get('offline-devices/{device}', [OfflineDeviceController::class, 'show'])->name('api.admin.offline-devices.show');
    Route::post('offline-devices/{device}/revoke', [OfflineDeviceController::class, 'revoke'])->name('api.admin.offline-devices.revoke');
    Route::post('offline-devices/{device}/restore', [OfflineDeviceController::class, 'restore'])->name('api.admin.offline-devices.restore');
    Route::get('offline/readiness', [OfflineDeviceController::class, 'readiness'])->name('api.admin.offline.readiness');

    // ── Settings ───────────────────────────────────────────────
    Route::get('settings/practice', [PracticeSettingsController::class, 'show'])->name('api.admin.settings.practice.show');
    Route::match(['put', 'patch'], 'settings/practice', [PracticeSettingsController::class, 'update'])->name('api.admin.settings.practice.update');
    Route::post('settings/practice/apply', [PracticeSettingsController::class, 'apply'])->name('api.admin.settings.practice.apply');

    Route::get('settings/hospital', [HospitalSettingsController::class, 'show'])->name('api.admin.settings.hospital.show');
    // POST (not PUT): the logos arrive as multipart.
    Route::post('settings/hospital', [HospitalSettingsController::class, 'update'])->name('api.admin.settings.hospital.update');
    Route::delete('settings/hospital/logo', [HospitalSettingsController::class, 'removeLogo'])->name('api.admin.settings.hospital.logo.destroy');
    Route::delete('settings/hospital/wordmark', [HospitalSettingsController::class, 'removeWordmark'])->name('api.admin.settings.hospital.wordmark.destroy');

    Route::get('settings/billing', [BillingSettingsController::class, 'show'])->name('api.admin.settings.billing.show');
    Route::match(['put', 'patch'], 'settings/billing', [BillingSettingsController::class, 'update'])->name('api.admin.settings.billing.update');

    // ── Account state ──────────────────────────────────────────
    Route::get('onboarding', OnboardingController::class)->name('api.admin.onboarding');
    // SaaS only; stays reachable when the subscription has ended (EnsureSubscribed::ALLOWED).
    Route::get('subscription', SubscriptionController::class)->middleware('edition:saas')->name('api.subscription.show');
    // Standalone only.
    Route::get('system', LicenceController::class)->middleware('edition:standalone')->name('api.admin.system');
});
