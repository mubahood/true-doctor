<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use App\Support\Onboarding\Step;
use App\Support\OnboardingStatus;
use App\Support\Practice\Practice;
use App\Support\StarterData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The setup checklist (access-admin, as the web's wizard): each step with
 * whether it is done and whether it blocks the rest of the system. State is
 * derived from the hospital's real data by OnboardingStatus — the object the
 * wizard and the redirect gate both read — so it is never stale. Only
 * `can_configure` users (manage-settings) can act on a step.
 */
class OnboardingController extends Controller
{
    public function __invoke(Request $request, OnboardingStatus $status): JsonResponse
    {
        abort_unless($request->user()->can('access-admin'), 403);

        $hospital = app(HospitalSettings::class)->hospital();
        $steps = $status->steps($hospital);
        $next = $status->nextStep($hospital);
        $practice = Practice::of($hospital);

        return ApiResponse::success([
            'complete' => $status->isComplete($hospital),
            'progress' => $status->progress($hospital),
            'can_configure' => $request->user()->can('manage-settings'),
            'next_step' => $next?->key,
            'issues' => $status->issues($hospital),
            'blocking' => $steps->filter(fn (Step $s) => $s->required && ! $s->done)->pluck('key')->values(),
            'steps' => $steps->map(fn (Step $s) => [
                'key' => $s->key,
                'title' => $s->title,
                'summary' => $s->summary,
                'why' => $s->why,
                'icon' => $s->icon,
                'required' => $s->required,
                'done' => $s->done,
                'status' => $s->statusLabel(),
                'tone' => $s->statusTone(),
                'detail' => $s->detail,
                'issues' => $s->issues,
                'web_route' => $s->route,
                // The web route name the app opens natively, and the starter data (GET admin/starter-data) that fills the step.
                'app_route' => StarterData::appRouteForStep($s->key, $practice),
                'starter_kind' => StarterData::kindForStep($s->key, $practice),
            ])->values(),
        ]);
    }
}
