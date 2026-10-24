<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
use Illuminate\Http\Request;

final class Filters
{
    public static function read(Request $request, bool $admin = false): array
    {
        $rules = [
            'source'=>'sometimes|in:all,web,yggdrasil,legacy',
            'action'=>'nullable|in:login,authenticate,refresh,join',
            'channel'=>['nullable','string','max:128','regex:/\A(?:password|wecom|remember|unknown|legacy|oauth(?::[a-z0-9][a-z0-9_-]{0,63})?|custom:[a-z0-9][a-z0-9_-]{0,63})\z/D'],
            'player'=>'nullable|integer|min:0',
            'from'=>'nullable|date_format:Y-m-d', 'to'=>'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
            'page'=>'sometimes|integer|min:1|max:100000',
        ];
        if ($admin) {$rules += ['uid'=>'nullable|integer|min:1|max:4294967295', 'user'=>'nullable|string|max:100', 'ip'=>'nullable|ip'];}
        $filters = $request->validate($rules);
        $filters += ['source'=>'all','action'=>'','player'=>'','from'=>'','to'=>'','channel'=>''];
        if ($admin) {$filters += ['uid'=>'', 'user'=>'', 'ip'=>''];}
        return $filters;
    }
}
