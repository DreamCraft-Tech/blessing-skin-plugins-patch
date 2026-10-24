<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
use Illuminate\Http\Request;

final class Controller
{
    public function index(Request $request, History $history)
    {
        $filters = Filters::read($request);
        abort_if($filters['source'] === 'legacy' && !$history->legacyAvailable(), 404);
        return response()->view('Blessing\\LoginHistory::history', [
            'records'=>$records = $history->records((int) $request->user()->uid, $filters),
            'pagination'=>\Illuminate\Pagination\UrlWindow::make($records), 'admin_mode'=>false,
            'players'=>$history->players((int) $request->user()->uid), 'filters'=>$filters,
            'channels'=>$history->channels((int) $request->user()->uid),
            'legacy_available'=>$history->legacyAvailable(), 'timezone'=>config('app.timezone', 'UTC'),
        ])->header('Cache-Control','no-store, private')->header('Referrer-Policy','same-origin');
    }
}
