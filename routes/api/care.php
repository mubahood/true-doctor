<?php

// API routes: care (scheduling + inpatient). Inside the authenticated, subscribed group of routes/api.php.
// Contract: docs/api/care.md.

use App\Http\Controllers\Api\V1\AdmissionController;
use App\Http\Controllers\Api\V1\NursingChartController;
use App\Http\Controllers\Api\V1\OccupancyController;
use App\Http\Controllers\Api\V1\ScheduleController;
use Illuminate\Support\Facades\Route;

// Doctor availability (admin.schedules.index).
Route::get('schedules', [ScheduleController::class, 'index'])->name('api.schedules.index');
Route::get('schedules/doctors/{doctor}', [ScheduleController::class, 'doctor'])->whereNumber('doctor')->name('api.schedules.doctor');
Route::post('schedules', [ScheduleController::class, 'store'])->name('api.schedules.store');
Route::put('schedules/{schedule}', [ScheduleController::class, 'update'])->whereNumber('schedule')->name('api.schedules.update');
Route::post('schedules/{schedule}/toggle', [ScheduleController::class, 'toggle'])->whereNumber('schedule')->name('api.schedules.toggle');
Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy'])->whereNumber('schedule')->name('api.schedules.destroy');

// Admissions (admin.admissions.index / show).
Route::get('admissions', [AdmissionController::class, 'index'])->name('api.admissions.index');
Route::post('admissions', [AdmissionController::class, 'store'])->name('api.admissions.store');
Route::get('admissions/{admission}', [AdmissionController::class, 'show'])->name('api.admissions.show');
Route::get('admissions/{admission}/vitals', [NursingChartController::class, 'vitals'])->name('api.admissions.vitals');
Route::post('admissions/{admission}/vitals', [NursingChartController::class, 'storeVitals'])->name('api.admissions.vitals.store');
Route::get('admissions/{admission}/medications', [NursingChartController::class, 'medications'])->name('api.admissions.medications');
Route::post('admissions/{admission}/medications', [NursingChartController::class, 'storeMedication'])->name('api.admissions.medications.store');
Route::get('admissions/{admission}/notes', [NursingChartController::class, 'notes'])->name('api.admissions.notes');
Route::post('admissions/{admission}/notes', [NursingChartController::class, 'storeNote'])->name('api.admissions.notes.store');

// Occupancy board (admin.admissions.board).
Route::get('occupancy', [OccupancyController::class, 'index'])->name('api.occupancy.index');
Route::get('occupancy/beds/{bed}', [OccupancyController::class, 'show'])->whereNumber('bed')->name('api.occupancy.bed');
Route::post('occupancy/beds/{bed}/status', [OccupancyController::class, 'status'])->whereNumber('bed')->name('api.occupancy.bed-status');
