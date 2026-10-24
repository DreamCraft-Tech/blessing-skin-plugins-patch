<?php

declare(strict_types=1);

namespace Blessing\DailyLuck;

use App\Models\User;
use Illuminate\Http\Request;

final class Configuration
{
    public function render(Request $request)
    {
        abort_unless($request->user() && $request->user()->permission >= User::ADMIN, 403);

        return response()->view('Blessing\\DailyLuck::config', [
            'settings' => app(Settings::class)->values(),
            'can_save' => $request->user()->permission >= User::SUPER_ADMIN,
            'timezone' => config('app.timezone', 'UTC'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request)
    {
        $data = $request->validate(['title' => 'required|string|max:40', 'position' => 'required|in:left,right', 'display' => 'required|in:reveal,visible']);
        option(['daily_luck_settings' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

        return redirect('admin/plugins/config/daily-luck')->with('daily_luck.notice', '每日人品配置已保存。');
    }
}
