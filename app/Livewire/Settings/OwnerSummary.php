<?php

namespace App\Livewire\Settings;

use App\Models\OwnerSummary as OwnerSummaryRow;
use App\Services\OwnerSummaryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "My daily summary", on Settings, Messages: the owner turns it on, picks
 * email or a text message and the time, and sees what it would say and what
 * went out the last few days (roadmap 09 §2). Rules in OwnerSummaryService.
 */
class OwnerSummary extends Component
{
    public bool $enabled = false;

    public string $channel = 'email';

    public string $at = OwnerSummaryService::DEFAULT_AT;

    /** What it would say, worked out once (it runs every figure in the summary), and again after a save. */
    #[\Livewire\Attributes\Locked]
    public string $preview = '';

    public function mount(OwnerSummaryService $summaries): void
    {
        abort_unless(OwnerSummaryService::isOwner(Auth::user()), 403);
        $prefs = $summaries->preferences(Auth::user());
        $this->enabled = $prefs['enabled'];
        $this->channel = $prefs['channel'];
        $this->at = $prefs['at'];
        $this->preview = $summaries->preview(Auth::user());
    }

    public function save(OwnerSummaryService $summaries): void
    {
        abort_unless(OwnerSummaryService::isOwner(Auth::user()), 403);

        try {
            $summaries->savePreferences(Auth::user(), ['enabled' => $this->enabled, 'channel' => $this->channel, 'at' => $this->at]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->resetErrorBag();
        $this->preview = $summaries->preview(Auth::user());

        $this->dispatch('toast', type: 'success', message: $this->enabled
            ? 'Saved. Your summary of the day before comes every day at '.$this->at.'.'
            : 'Saved. No daily summary will be sent.');
    }

    public function render(OwnerSummaryService $summaries): View
    {
        return view('livewire.settings.owner-summary', [
            'channels' => $summaries->channels(),
            'recent' => OwnerSummaryRow::query()->where('user_id', Auth::id())->latest('summary_date')->limit(7)->get(),
        ]);
    }
}
