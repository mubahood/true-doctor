<?php

// API routes: finance, cards and insurance. Inside the authenticated, subscribed group of routes/api.php.
// Each endpoint is authorised exactly as its web page is (docs/api/finance.md).

use App\Http\Controllers\Admin\FinanceExportController;
use App\Http\Controllers\Admin\InsuranceProviderController as InsuranceProviderUsagePdf;
use App\Http\Controllers\Api\V1\Finance\CardController;
use App\Http\Controllers\Api\V1\Finance\CardRecordController;
use App\Http\Controllers\Api\V1\Finance\FinanceController;
use App\Http\Controllers\Api\V1\Finance\FinancialYearController;
use App\Http\Controllers\Api\V1\Finance\InsuranceClaimController;
use App\Http\Controllers\Api\V1\Finance\InsuranceProviderController;
use App\Http\Controllers\Api\V1\Finance\JournalController;
use App\Http\Controllers\Api\V1\Finance\LedgerAccountController;
use App\Http\Controllers\Api\V1\Finance\StatementController;
use App\Http\Controllers\Api\V1\Finance\TransactionController;
use Illuminate\Support\Facades\Route;

// ── The books ────────────────────────────────────────────────────────────
Route::get('finance', [FinanceController::class, 'overview'])->name('api.finance.overview');
Route::get('finance/options', [FinanceController::class, 'options'])->name('api.finance.options');
Route::post('finance/catch-up', [FinanceController::class, 'catchUp'])->name('api.finance.catch-up');
Route::get('finance/settings', [FinanceController::class, 'settings'])->name('api.finance.settings');
Route::put('finance/settings', [FinanceController::class, 'updateSettings'])->name('api.finance.settings.update');

Route::get('finance/transactions', [TransactionController::class, 'index'])->name('api.finance.transactions.index');
Route::post('finance/transactions', [TransactionController::class, 'store'])->name('api.finance.transactions.store');

Route::get('finance/journal', [JournalController::class, 'index'])->name('api.finance.journal.index');
Route::post('finance/journal', [JournalController::class, 'store'])->name('api.finance.journal.store');
Route::post('finance/journal/opening', [JournalController::class, 'opening'])->name('api.finance.journal.opening');
Route::get('finance/journal/{entry}', [JournalController::class, 'show'])->name('api.finance.journal.show');
Route::post('finance/journal/{entry}/reverse', [JournalController::class, 'reverse'])->name('api.finance.journal.reverse');

Route::get('finance/accounts', [LedgerAccountController::class, 'index'])->name('api.finance.accounts.index');
Route::post('finance/accounts', [LedgerAccountController::class, 'store'])->name('api.finance.accounts.store');
Route::get('finance/accounts/{account}', [LedgerAccountController::class, 'show'])->name('api.finance.accounts.show');
Route::put('finance/accounts/{account}', [LedgerAccountController::class, 'update'])->name('api.finance.accounts.update');
Route::post('finance/accounts/{account}/toggle', [LedgerAccountController::class, 'toggle'])->name('api.finance.accounts.toggle');
Route::delete('finance/accounts/{account}', [LedgerAccountController::class, 'destroy'])->name('api.finance.accounts.destroy');

Route::get('finance/statements', [StatementController::class, 'show'])->name('api.finance.statements.show');
// The web's own downloads, behind the same finance.view check.
Route::get('finance/statements/pdf', [FinanceExportController::class, 'pdf'])->name('api.finance.statements.pdf');
Route::get('finance/export', [FinanceExportController::class, 'csv'])->name('api.finance.export');

Route::get('financial-years', [FinancialYearController::class, 'index'])->name('api.financial-years.index');
Route::post('financial-years', [FinancialYearController::class, 'store'])->name('api.financial-years.store');
Route::get('financial-years/{financialYear}', [FinancialYearController::class, 'show'])->name('api.financial-years.show');
Route::post('financial-years/{financialYear}/close', [FinancialYearController::class, 'close'])->name('api.financial-years.close');
Route::post('financial-years/{financialYear}/reopen', [FinancialYearController::class, 'reopen'])->name('api.financial-years.reopen');

// ── Cards ────────────────────────────────────────────────────────────────
Route::get('cards', [CardController::class, 'index'])->name('api.cards.index');
Route::get('cards/options', [CardController::class, 'options'])->name('api.cards.options');
Route::post('cards/lookup', [CardController::class, 'lookup'])->middleware('throttle:30,1')->name('api.cards.lookup');
Route::get('cards/{card}', [CardController::class, 'show'])->name('api.cards.show');
Route::get('cards/{card}/records', [CardController::class, 'records'])->name('api.cards.records');
Route::post('cards/{card}/transactions', [CardController::class, 'transact'])->name('api.cards.transact');
Route::put('cards/{card}/terms', [CardController::class, 'terms'])->name('api.cards.terms');
Route::post('cards/{card}/holders', [CardController::class, 'addHolder'])->name('api.cards.holders.store');
Route::delete('cards/{card}/holders/{holder}', [CardController::class, 'revokeHolder'])->whereNumber('holder')->name('api.cards.holders.destroy');
Route::post('cards/{card}/settle', [CardController::class, 'settle'])->name('api.cards.settle');
Route::get('patients/{patient}/cards', [CardController::class, 'forPatient'])->name('api.patients.cards.index');
Route::post('patients/{patient}/cards', [CardController::class, 'issue'])->name('api.patients.cards.store');

Route::get('card-records', [CardRecordController::class, 'index'])->name('api.card-records.index');
Route::get('card-records/{cardRecord:uuid}', [CardRecordController::class, 'show'])->name('api.card-records.show');

// ── Insurance ────────────────────────────────────────────────────────────
Route::get('insurance-claims', [InsuranceClaimController::class, 'index'])->name('api.insurance-claims.index');
Route::post('insurance-claims', [InsuranceClaimController::class, 'store'])->name('api.insurance-claims.store');
Route::get('insurance-claims/{insuranceClaim}', [InsuranceClaimController::class, 'show'])->name('api.insurance-claims.show');
Route::post('insurance-claims/{insuranceClaim}/transition', [InsuranceClaimController::class, 'transition'])->name('api.insurance-claims.transition');

Route::get('insurance-providers', [InsuranceProviderController::class, 'index'])->name('api.insurance-providers.index');
Route::post('insurance-providers', [InsuranceProviderController::class, 'store'])->name('api.insurance-providers.store');
Route::get('insurance-providers/{insuranceProvider}', [InsuranceProviderController::class, 'show'])->name('api.insurance-providers.show');
Route::put('insurance-providers/{insuranceProvider}', [InsuranceProviderController::class, 'update'])->name('api.insurance-providers.update');
Route::get('insurance-providers/{insuranceProvider}/ledger', [InsuranceProviderController::class, 'ledger'])->name('api.insurance-providers.ledger');
Route::get('insurance-providers/{insuranceProvider}/usage', [InsuranceProviderUsagePdf::class, 'usagePdf'])->name('api.insurance-providers.usage');
Route::post('insurance-providers/{insuranceProvider}/deposit', [InsuranceProviderController::class, 'deposit'])->name('api.insurance-providers.deposit');
Route::post('insurance-providers/{insuranceProvider}/adjust', [InsuranceProviderController::class, 'adjust'])->name('api.insurance-providers.adjust');
Route::post('insurance-providers/{insuranceProvider}/settle', [InsuranceProviderController::class, 'settleAll'])->name('api.insurance-providers.settle');
Route::post('insurance-providers/{insuranceProvider}/cards/{card}/settle', [InsuranceProviderController::class, 'settleCard'])->name('api.insurance-providers.cards.settle');
