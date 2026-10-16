<?php

// API routes: notifications, devices, preferences, reminders (Super app plan W1-D).
// Inside the authenticated, subscribed group of routes/api.php.
// (GET notifications, POST notifications/read-all and notifications/{id}/read stay in routes/api.php.)

use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\ReminderController;
use Illuminate\Support\Facades\Route;

Route::get('devices', [DeviceController::class, 'index'])->name('api.devices.index');
Route::put('devices/{deviceUuid}/push-token', [DeviceController::class, 'pushToken'])->name('api.devices.push-token');
Route::delete('devices/{deviceUuid}', [DeviceController::class, 'destroy'])->name('api.devices.destroy');

Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('api.notification-preferences.show');
Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('api.notification-preferences.update');

Route::get('reminders/upcoming', [ReminderController::class, 'upcoming'])->name('api.reminders.upcoming');
