<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OwnerSummary;
use App\Services\OwnerSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The owner's daily summary: their choice, a preview and the last week (docs/api/owner-summary.md). */
class OwnerSummaryController extends Controller
{
    public function __construct(private readonly OwnerSummaryService $summaries) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless(OwnerSummaryService::isOwner($request->user()), 403);

        return ApiResponse::success($this->payload($request) + ['preview' => $this->summaries->preview($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(OwnerSummaryService::isOwner($request->user()), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'channel' => ['required', 'in:'.implode(',', array_keys(OwnerSummaryService::CHANNELS))],
            'at' => ['required', 'date_format:H:i'],
        ]);

        $this->summaries->savePreferences($request->user(), $data);

        return ApiResponse::success($this->payload($request), 'Saved.');
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $user = $request->user();
        $prefs = $this->summaries->preferences($user);

        return $prefs + [
            'channels' => array_map(fn ($name, $label) => ['value' => $name, 'label' => $label], array_keys($this->summaries->channels()), $this->summaries->channels()),
            'recent' => OwnerSummary::query()->where('user_id', $user->id)->latest('summary_date')->limit(7)->get()
                ->map(fn (OwnerSummary $s) => [
                    'date' => $s->summary_date->toDateString(),
                    'channel' => $s->channel,
                    'status' => $s->status,
                    'error' => $s->error,
                    'sent_at' => $s->sent_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
