<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
use Illuminate\Http\Request;

final class Controller
{
    public function index(Request $request, History $history)
    {
        $filters = $request->validate([
            'source'=>'sometimes|in:all,web,yggdrasil,legacy',
            'action'=>'nullable|in:login,authenticate,refresh,join',
            'channel'=>['nullable','string','max:128','regex:/\A(?:password|wecom|remember|unknown|legacy|oauth(?::[a-z0-9][a-z0-9_-]{0,63})?|custom:[a-z0-9][a-z0-9_-]{0,63})\z/D'],
            'player'=>'nullable|integer|min:0',
            'from'=>'nullable|date_format:Y-m-d', 'to'=>'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'page'=>'sometimes|integer|min:1|max:100000',
        ]);
        $filters += ['source'=>'all','action'=>'','player'=>'','from'=>'','to'=>'','channel'=>''];
        abort_if($filters['source'] === 'legacy' && !$history->legacyAvailable(), 404);
        return response()->view('Blessing\\LoginHistory::history', [
            'records'=>$history->records((int) $request->user()->uid, $filters),
            'players'=>$history->players((int) $request->user()->uid), 'filters'=>$filters,
            'channels'=>$history->channels((int) $request->user()->uid),
            'legacy_available'=>$history->legacyAvailable(), 'timezone'=>config('app.timezone', 'UTC'),
        ])->header('Cache-Control','no-store, private')->header('Referrer-Policy','same-origin');
    }
}
