<?php

namespace App\Http\Controllers;

use App\Support\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EditionHomeController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (Edition::isStandalone()) {
            return redirect()->route(auth()->check() ? 'admin.dashboard' : 'admin.login');
        }

        return view('marketing.home');
    }
}
