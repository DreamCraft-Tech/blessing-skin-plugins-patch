<?php

declare(strict_types=1);
namespace Blessing\WeCom;

use Illuminate\Http\Request;

final class GeoDatabaseController
{
    public function start(GeoDatabaseUpdater $updater) {return $this->result(fn () => $updater->start());}
    public function status(GeoDatabaseUpdater $updater) {return $this->result(fn () => $updater->status());}
    public function step(Request $request, GeoDatabaseUpdater $updater)
    {
        $input = $request->validate(['id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/D']]);
        return $this->result(fn () => $updater->step($input['id']));
    }
    private function result(callable $callback)
    {
        try {return response()->json($callback())->header('Cache-Control', 'no-store');}
        catch (\Throwable) {return response()->json(['phase' => 'error', 'message' => '无法处理城市库更新，请检查目录权限或稍后重试。'], 409)->header('Cache-Control', 'no-store');}
    }
}
