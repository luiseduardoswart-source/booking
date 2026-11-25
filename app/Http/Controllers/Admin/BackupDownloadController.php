<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\Backups as BackupsPage;
use App\Http\Controllers\Controller;
use App\Support\Backups\Backups;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download of a backup: owner only, and only a file that really is a backup
 * (a name like backup-2026-11-25-023000.zip; nothing else in the folder or
 * outside it). Streamed from disk, so a large backup does not pass through
 * memory. Every download is written to the log.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, string $name): BinaryFileResponse
    {
        abort_if($request->user() === null, 401);
        abort_unless(BackupsPage::canAccess(), 403);

        $path = Backups::path($name);
        abort_if($path === null, 404);

        activity()->event('backup_downloaded')->withProperties(['backup' => $name])->log('backup_downloaded');

        return response()->download($path, $name, ['Content-Type' => 'application/zip']);
    }
}
