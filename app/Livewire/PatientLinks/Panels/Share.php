<?php

namespace App\Livewire\PatientLinks\Panels;

use App\Enums\SharedDocumentType;
use App\Models\Patient;
use App\Models\PatientLink;
use App\Services\PatientLinks\PatientLinkService;
use App\Services\PatientLinks\SharedDocuments;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Send to patient": a button beside a document's PDF button that makes a
 * patient link to it (roadmap 05 §4) and, when the patient has said yes to
 * texts, sends it.
 *
 * Shows nothing to someone who may not print the document, or while the
 * document is not ready (an unreleased result, a patient still on the ward).
 * The link and its code are shown once, right after it is made: only their
 * hashes are kept.
 */
class Share extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $type = '';

    #[Locked]
    public string $uuid = '';

    /** Small, for a row of small buttons (the visit's, the dental tab's). */
    #[Locked]
    public bool $small = false;

    public bool $showShare = false;

    public int $days = PatientLinkService::DAYS;

    public bool $sendSms = true;

    /** @var array{url:string,code:string,message:?string}|null */
    public ?array $result = null;

    public function mount(string $type, string $uuid, bool $small = false): void
    {
        $this->type = $type;
        $this->uuid = $uuid;
        $this->small = $small;
    }

    public function open(): void
    {
        abort_unless($this->allowed(), 403);
        $this->reset(['result', 'days']);
        $this->sendSms = $this->canText($this->patient());
        $this->resetErrorBag();
        $this->showShare = true;
    }

    public function share(): void
    {
        $links = app(PatientLinkService::class);
        abort_unless($this->allowed(), 403);
        $record = $this->record();
        abort_if($record === null, 404);

        $this->validate(['days' => 'required|integer|in:'.implode(',', PatientLinkService::DAY_CHOICES)]);

        try {
            $made = $links->issue($this->documentType(), $record, auth()->user(), $this->days, $this->sendSms && $this->canText($this->patient()));
        } catch (ValidationException $e) {
            $this->addError('days', $e->validator->errors()->first());

            return;
        }

        $this->result = [
            'url' => $made['url'],
            'code' => $made['code'],
            'message' => $made['message'] === null
                ? null
                : ($made['message']->skip_reason !== null
                    ? 'Not texted: '.str_replace('_', ' ', $made['message']->skip_reason).'.'
                    : 'Text message '.mb_strtolower($made['message']->status->label()).'.'),
        ];
        $this->dispatch('toast', type: 'success', message: 'Link ready.');
    }

    public function revoke(int $id): void
    {
        $links = app(PatientLinkService::class);
        abort_unless($this->allowed(), 403);
        $record = $this->record();
        $link = PatientLink::query()->whereKey($id)
            ->where('document_type', $this->documentType()->value)
            ->where('document_id', $record?->getKey())
            ->firstOrFail();

        $links->revoke($link, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Link withdrawn.');
    }

    public function render()
    {
        $record = $this->allowed() ? $this->record() : null;
        $ready = $record !== null && app(SharedDocuments::class)->notReady($this->documentType(), $record) === null;
        $patient = $ready ? $this->patient() : null;

        return view('livewire.patient-links.panels.share', [
            'ready' => $ready,
            'label' => $this->documentType()->label(),
            'canText' => $this->canText($patient),
            'textNote' => $this->textNote($patient),
            'existing' => $ready ? $this->existing($record) : new Collection,
            'choices' => PatientLinkService::DAY_CHOICES,
        ]);
    }

    private function documentType(): SharedDocumentType
    {
        return SharedDocumentType::tryFrom($this->type) ?? abort(404);
    }

    private function allowed(): bool
    {
        return $this->documentType()->allows(auth()->user());
    }

    private function record(): ?Model
    {
        return app(SharedDocuments::class)->find($this->documentType(), $this->uuid);
    }

    private function patient(): ?Patient
    {
        $record = $this->record();

        return $record === null ? null : app(SharedDocuments::class)->patientOf($this->documentType(), $record);
    }

    /** Texts are on for the hospital, and the patient said yes to texts about their care (PatientLinkService). */
    private function canText(?Patient $patient): bool
    {
        return $patient !== null && app(PatientLinkService::class)->canText($patient);
    }

    private function textNote(?Patient $patient): ?string
    {
        return $patient === null ? null : app(PatientLinkService::class)->textNote($patient);
    }

    /** @return Collection<int,PatientLink> */
    private function existing(Model $record): Collection
    {
        return PatientLink::query()->with('creator:id,name')
            ->where('document_type', $this->documentType()->value)
            ->where('document_id', $record->getKey())
            ->latest('id')->limit(5)->get();
    }
}
