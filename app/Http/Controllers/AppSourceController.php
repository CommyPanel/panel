<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Deploy\Source\InvalidArchive;
use App\Deploy\Source\UploadStorage;
use App\Enums\SourceType;
use App\Models\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replaces the project of an upload app. The new code goes live with the next deployment.
 */
class AppSourceController extends Controller
{
    public function upload(Request $request, App $app, UploadStorage $uploads, AuditLogger $audit): RedirectResponse
    {
        abort_unless($app->source_type === SourceType::Upload, 404);

        $request->validate([
            'archive' => ['required', 'file', 'max:'.((int) config('commypanel.uploads.max_archive_mb') * 1024)],
        ], ['archive.max' => 'That file is too large.']);

        try {
            $count = $uploads->store($app, $request->file('archive'));
        } catch (InvalidArchive $e) {
            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        $app->forceFill(['source_verified_at' => now()])->save();
        $audit->record('app.source_uploaded', null, ['app' => $app->slug, 'files' => $count], $app);

        return back()->with('status', __('Project uploaded (:count files). Press Deploy to run it.', ['count' => $count]));
    }
}
