<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use App\Support\Manual;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The user manual (App\Support\Manual): public pages on the online service,
 * the PDF a one-off buyer downloads, and the phone's reader. The back-office
 * reader is App\Livewire\Manual\Show.
 */
class ManualController extends Controller
{
    public function index(): View
    {
        return view('marketing.manual.index', ['chapters' => Manual::chapters()]);
    }

    public function show(string $slug): View
    {
        $chapter = Manual::chapter($slug) ?? abort(404);

        return view('marketing.manual.show', ['chapter' => $chapter, 'html' => Manual::html($chapter['markdown'], 'manual.show'), 'chapters' => Manual::chapters()]);
    }

    /** The whole manual as one PDF, made once per version of the text and kept. */
    public function pdf(): BinaryFileResponse
    {
        return response()->file(self::pdfPath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="True-Doctor user manual.pdf"',
        ]);
    }

    public static function pdfPath(): string
    {
        $path = storage_path('app/manual/user-manual-'.Manual::version().'.pdf');
        if (! is_file($path)) {
            @mkdir(dirname($path), 0775, true);
            foreach (glob(dirname($path).'/user-manual-*.pdf') ?: [] as $old) {
                @unlink($old);
            }
            $chapters = array_map(function (array $c) {
                $chapter = Manual::chapter($c['slug']);

                // Links between chapters become links within the PDF.
                return $c + ['html' => (string) preg_replace('/href="[^"]*\/manual\/(\d{2}-[a-z0-9-]+)(#[^"]*)?"/', 'href="#$1"', Manual::html((string) $chapter['markdown'], 'admin.manual'))];
            }, Manual::chapters());
            Pdf::loadView('pdf.manual', ['chapters' => $chapters, 'version' => Manual::version()])->setPaper('a4')->save($path);
        }

        return $path;
    }

    /** GET api/v1/manual */
    public function apiIndex(): JsonResponse
    {
        return ApiResponse::success(['version' => Manual::version(), 'chapters' => Manual::chapters()]);
    }

    /** GET api/v1/manual/{slug} */
    public function apiShow(string $slug): JsonResponse
    {
        return ApiResponse::success(Manual::chapter($slug) ?? abort(404));
    }
}
