<?php

namespace App\Support\Insights;

/**
 * One suggestion for one person: what to look at, why, and where to go.
 *
 * `route` is the name of a WEB route (the same names App\Support\Navigation
 * uses), which the app maps to a native screen. `score` only orders the list
 * and is not part of the contract.
 */
final readonly class Insight
{
    public const TONES = ['info', 'warn', 'danger', 'success'];

    private const TONE_WEIGHT = ['danger' => 400, 'warn' => 300, 'info' => 200, 'success' => 100];

    public function __construct(
        public string $key,
        public string $tone,
        public string $title,
        public string $detail,
        public string $route,
        public string $actionLabel,
        public ?int $count = null,
        public int $priority = 50,
    ) {
        if (! in_array($tone, self::TONES, true)) {
            throw new \InvalidArgumentException("Unknown insight tone [{$tone}].");
        }
    }

    /** Danger above warn above info above success; a rule's own priority breaks ties. */
    public function score(): int
    {
        return self::TONE_WEIGHT[$this->tone] + max(0, min(99, $this->priority));
    }

    /** @return array{key:string,tone:string,title:string,detail:string,count:int|null,route:string,web_url:string|null,action_label:string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'tone' => $this->tone,
            'title' => $this->title,
            'detail' => $this->detail,
            'count' => $this->count,
            'route' => $this->route,
            'web_url' => \Illuminate\Support\Facades\Route::has($this->route) ? route($this->route) : null,
            'action_label' => $this->actionLabel,
        ];
    }
}
