<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Viewed
{
    public function result(int $uid): array
    {
        $result = app(Luck::class)->today($uid);
        $date = DB::table(Installer::TABLE)->where('user_id', $uid)->value('viewed_date');

        return $result + ['opened' => $date === $result['date']];
    }

    public function reveal(Request $request)
    {
        abort_unless(app(Settings::class)->values()['luck_enabled'], 404);
        $uid = (int) $request->user()->uid;
        $result = app(Luck::class)->today($uid);
        DB::table(Installer::TABLE)->upsert([['user_id' => $uid, 'viewed_date' => $result['date']]], ['user_id'], ['viewed_date']);

        return redirect('user#daily-luck-card')->header('Cache-Control', 'no-store, private');
    }
}
