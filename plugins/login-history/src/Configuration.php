<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\UrlWindow;

final class Configuration
{
    public function render(Request $request, History $history)
    {
        abort_unless($request->user() && $request->user()->permission >= User::ADMIN, 403);
        $filters = Filters::read($request, true);
        abort_if($filters['source'] === 'legacy' && !$history->legacyAvailable(), 404);
        $records = $history->records(null, $filters);
        return response()->view('Blessing\\LoginHistory::config', [
            'records'=>$records, 'pagination'=>UrlWindow::make($records),
            'admin_mode'=>true, 'filters'=>$filters, 'channels'=>$history->channels(null),
            'legacy_available'=>$history->legacyAvailable(), 'timezone'=>config('app.timezone', 'UTC'),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
    }
}
