<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Livewire\Dashboard\Index as DashboardPage;
use App\Support\ApiResponse;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Dashboard\StatCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/dashboard — the stat cards at the top of this person's
 * dashboard, exactly as the web draws them.
 *
 * Same role view (DashboardPage::roleViewFor), same figures (DashboardWidgets,
 * through its 45-second cache), same cards (StatCards) — the app renders what
 * the web renders, and the two cannot disagree about a number or a label.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardWidgets $widgets): JsonResponse
    {
        $user = $request->user();
        $view = DashboardPage::roleViewFor($user);
        $widget = $view.'.stats';

        $data = DashboardWidgets::allows($view, $widget, $user)
            ? $widgets->data($widget, $view, $user)
            : [];

        $cards = array_map(fn (array $card) => $card + [
            'web_url' => $card['route'] ? route($card['route']) : null,
        ], StatCards::for($view, $data, $user));

        return ApiResponse::success([
            'role_view' => $view,
            'cards' => $cards,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
