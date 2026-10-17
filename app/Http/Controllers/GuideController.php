<?php

namespace App\Http\Controllers;

use App\Support\HelpGuides;
use Illuminate\View\View;

/** The guides for clinic owners on the public site (config/guides.php, roadmap 02 §7). */
class GuideController extends Controller
{
    public function index(): View
    {
        return view('marketing.guides.index', [
            'page' => (array) config('guides.hub'),
            'guides' => HelpGuides::all(),
        ]);
    }

    public function show(string $slug): View
    {
        $guide = HelpGuides::find($slug);
        abort_if($guide === null, 404);

        return view('marketing.guides.show', [
            'slug' => $slug,
            'guide' => $guide,
            'author' => (array) config('guides.author'),
            'others' => array_diff_key(HelpGuides::all(), [$slug => true]),
        ]);
    }
}
