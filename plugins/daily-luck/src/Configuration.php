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
        $data = $request->validate(['title' => 'required|string|max:40', 'position' => 'required|in:left,right', 'display' => 'required|in:reveal,visible', 'luck_enabled' => 'sometimes|boolean', 'prank_enabled' => 'sometimes|boolean', 'texts' => 'nullable|string|max:32000', 'effect' => 'sometimes|in:rainbow,paper,fly,shake,pulse,chaos,dodge,mixed', 'motion' => 'sometimes|in:gentle,playful,wild', 'duration' => 'sometimes|integer|in:15,30,60']);
        $data = array_replace(app(Settings::class)->values(), $data);
        $data['luck_enabled'] = (bool) $data['luck_enabled'];
        $data['prank_enabled'] = (bool) $data['prank_enabled'];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['texts'] ?? '')), fn ($line) => $line !== ''));
        if (count($lines) > 100 || collect($lines)->contains(fn ($line) => mb_strlen($line) > 200)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['texts' => '请填写最多 100 条短评，每条最多 200 字。']);
        }
        $data['texts'] = implode("\n", $lines);
        option(['daily_luck_settings' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

        return redirect('admin/plugins/config/daily-luck')->with('daily_luck.notice', '今日人品与千万别点配置已保存。');
    }
}
