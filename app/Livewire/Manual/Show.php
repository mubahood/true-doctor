<?php

namespace App\Livewire\Manual;

use App\Support\Manual;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The user manual inside the back office (resources/manual): every signed-in
 * person, both editions, so a one-off copy carries its own manual.
 */
#[Layout('layouts.admin')]
class Show extends Component
{
    public string $slug = '';

    public function mount(?string $slug = null): void
    {
        $chapters = Manual::chapters();
        $this->slug = $slug ?? ($chapters[0]['slug'] ?? '');
        abort_if($slug !== null && Manual::chapter($slug) === null, 404);
    }

    public function render(): View
    {
        $chapter = Manual::chapter($this->slug);

        return view('livewire.manual.show', [
            'chapters' => Manual::chapters(),
            'chapter' => $chapter,
            'html' => $chapter ? Manual::html($chapter['markdown'], 'admin.manual') : '',
        ])->title($this->slug === (Manual::chapters()[0]['slug'] ?? '') ? 'User manual' : ($chapter['title'] ?? 'User manual').' · User manual');
    }
}
