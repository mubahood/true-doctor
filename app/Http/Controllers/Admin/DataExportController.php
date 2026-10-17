<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Compliance\HospitalExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A hospital's full export, through its encrypted link (docs/compliance/README.md).
 * A link that has expired, was tampered with, or names another hospital's
 * export is a 404; every download is counted and logged.
 */
class DataExportController extends Controller
{
    public function __invoke(Request $request, string $token, HospitalExport $exports): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user?->can('manage-settings'), 403);

        $export = $exports->open($token, $user) ?? abort(404, 'This link has expired or is not yours. Ask for a new export.');
        $exports->recordDownload($export, $user);

        $name = 'data-export-'.($export->created_at?->format('Ymd-Hi') ?? 'hospital').'.zip';

        return response()->download($exports->absolutePath($export), $name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
