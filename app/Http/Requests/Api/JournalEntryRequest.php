<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\CashEntryRequest;
use Illuminate\Foundation\Http\FormRequest;

/** An accountant's own journal (finance.manage); balance is CashbookService's to judge. */
class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.manage');
    }

    public function rules(): array
    {
        return CashEntryRequest::journalRules();
    }
}
