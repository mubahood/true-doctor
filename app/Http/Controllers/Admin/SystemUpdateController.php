<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Install\InstallationBackups;
use App\Services\Install\StandaloneUpdater;
use App\Services\Install\SystemState;
use App\Support\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The two parts of Licence & updates that move whole files: an update package
 * coming in (a plain upload — Livewire's temporary uploads are capped well
 * below the size of a release), and a backup going out.
 */
class SystemUpdateController extends Controller
{
    public function upload(Request $request, StandaloneUpdater $updater, SystemState $state): RedirectResponse
    {
        $this->authorizeOwner($request);

        $request->validate(['package' => ['required', 'file', 'max:512000']], [
            'package.max' => 'That file is larger than this server accepts. Put it in the updates folder with File Manager instead.',
        ]);

        $file = $request->file('package');
        $name = 'uploaded-'.gmdate('Ymd-His').'.zip';
        $path = $file->storeAs('updates', $name, 'local');   // storage/app/private/updates
        $absolute = storage_path('app/private/'.$path);

        try {
            $checked = $updater->inspect($absolute);
            $state->request(['action' => 'update', 'source' => 'upload', 'archive' => $absolute, 'version' => $checked['version'], 'by' => $request->user()->id]);
        } catch (\DomainException $e) {
            @unlink($absolute);

            return redirect()->route('admin.system.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.system.index')->with('success', "True-Doctor {$checked['version']} verified. The update starts within a minute.");
    }

    public function backup(Request $request, string $name, InstallationBackups $backups): BinaryFileResponse
    {
        $this->authorizeOwner($request);
        $path = $backups->path($name);
        abort_if($path === null, 404);

        return response()->download($path, $name, ['Cache-Control' => 'no-store, private']);
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless(Edition::isStandalone(), 404);
        abort_unless($request->user()?->can('manage-settings'), 403);
    }
}
