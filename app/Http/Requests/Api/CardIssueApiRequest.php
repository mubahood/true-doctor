<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\CardIssueRequest;

class CardIssueApiRequest extends CardIssueRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('patients.card.manage');
    }
}
