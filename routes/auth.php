<?php

use App\Http\Controllers\Account\AccountSecurityController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,10')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');

    // The second step of signing in, before anybody is signed in.
    Route::get('two-factor/challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('two-factor.verify');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::get('password/change', fn () => view('auth.force-password-change'))
        ->name('password.change');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    // Account security: two-step sign-in, trusted browsers, signed-in apps.
    Route::middleware('no-store')->prefix('account/security')->name('account.security')->group(function () {
        Route::get('/', [AccountSecurityController::class, 'show']);
        Route::post('two-factor', [AccountSecurityController::class, 'begin'])->name('.begin');
        Route::post('two-factor/confirm', [AccountSecurityController::class, 'confirm'])->middleware('throttle:12,1')->name('.confirm');
        Route::post('recovery-codes', [AccountSecurityController::class, 'regenerate'])->name('.recovery');
        Route::delete('two-factor', [AccountSecurityController::class, 'disable'])->name('.disable');
        Route::delete('browsers', [AccountSecurityController::class, 'forgetBrowsers'])->name('.browsers');
        Route::delete('devices/{device}', [AccountSecurityController::class, 'revokeDevice'])->whereNumber('device')->name('.device');
        Route::post('sign-out-everywhere', [AccountSecurityController::class, 'signOutEverywhere'])->name('.everywhere');
    });
});
