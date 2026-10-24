<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class History
{
    public function legacyAvailable(): bool
    {
        return Schema::hasTable('ygg_log') && Schema::hasColumns('ygg_log', ['id','action','user_id','player_id','ip','time']);
    }

    public function records(int $uid, array $filters)
    {
        $timezone = config('app.timezone', 'UTC');
        $legacy = $filters['source'] === 'legacy';
        $from = !empty($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay() : null;
        $to = !empty($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay() : null;
        $oldest = now($legacy ? $timezone : 'UTC')->subDays(Recorder::DAYS);
        if (!$legacy) {$from = $from?->utc(); $to = $to?->utc();}
        $time = $legacy ? 'time' : 'occurred_at';
        if ($legacy) {
            $query = DB::table('ygg_log')->select(['id','action','user_id','player_id','ip','time'])
                ->where('user_id', $uid)->whereIn('action', ['authenticate','refresh','join']);
        } else {
            $query = DB::table(Installer::ENTRIES)->where('user_id', $uid);
            if ($filters['source'] !== 'all') {$query->where('source', $filters['source']);}
        }
        if (!empty($filters['channel'])) {
            if ($legacy) {$query->whereRaw('1 = 0');}
            else {
                $query->where('source', 'web');
                if ($filters['channel'] === 'legacy') {$query->whereNull('login_channel');}
                else {$query->where('login_channel', $filters['channel']);}
            }
        }
        $query->where($time, '>=', $oldest);
        if ($from) {$query->where($time, '>=', $from);}
        if ($to) {$query->where($time, '<=', $to);}
        if (!empty($filters['action'])) {$query->where('action', $filters['action']);}
        if (isset($filters['player']) && $filters['player'] !== '') {
            $pid = (int) $filters['player'];
            if ($legacy) {$query->where('player_id', $pid);}
            elseif ($pid === 0) {
                $query->where('source', 'yggdrasil')->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')->from(Installer::PROFILES)
                        ->whereColumn(Installer::PROFILES.'.entry_id', Installer::ENTRIES.'.id')->where('selected', true);
                });
            } else {
                $query->whereExists(function ($sub) use ($pid) {
                    $sub->selectRaw('1')->from(Installer::PROFILES)
                        ->whereColumn(Installer::PROFILES.'.entry_id', Installer::ENTRIES.'.id')
                        ->where('player_id', $pid)->where('selected', true);
                });
            }
        }
        $page = $query->orderByDesc($time)->orderByDesc('id')->paginate(20)->withQueryString();
        $profiles = $legacy ? collect() : DB::table(Installer::PROFILES)->whereIn('entry_id', $page->pluck('id'))->get()->groupBy('entry_id');
        $labels = ['login'=>'网页登录','authenticate'=>'客户端认证','refresh'=>'刷新令牌 / 选择角色','join'=>'请求加入服务器'];
        $page->getCollection()->transform(function ($row) use ($legacy, $profiles, $labels, $timezone) {
            $row->action_label = $labels[$row->action] ?? $row->action;
            $row->profiles = $legacy ? collect() : $profiles->get($row->id, collect());
            $row->selected_profiles = $row->profiles->where('selected', true)->values();
            $row->candidate_profiles = $row->profiles->where('selected', false)->values();
            $row->source_label = $legacy ? '旧版 Yggdrasil 日志' : ($row->source === 'web' ? '网页' : 'Yggdrasil');
            $row->user_agent = $legacy ? '' : $row->user_agent;
            $row->client_label = app(ClientAgent::class)->label($row->user_agent);
            $row->channel_label = $legacy ? '未记录' : ($row->source === 'web'
                ? WebChannels::label($row->login_channel, $row->backend)
                : match ($row->backend) {'yggdrasil-api' => 'Yggdrasil API', 'yggdrasil-connect' => 'Yggdrasil Connect', default => 'Yggdrasil'});
            $row->display_time = CarbonImmutable::parse($legacy ? $row->time : $row->occurred_at, $legacy ? $timezone : 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
            if ($legacy) {$row->legacy_player = (int) $row->player_id;}
            return $row;
        });
        return $page;
    }

    public function channels(int $uid): array
    {
        $values = ['password', 'wecom', 'oauth:github', 'oauth:google', 'oauth:live', 'oauth:littleskin', 'remember', 'unknown', 'legacy'];
        $historical = DB::table(Installer::ENTRIES)->where('user_id', $uid)->where('source', 'web')
            ->where('occurred_at', '>=', now('UTC')->subDays(Recorder::DAYS))->whereNotNull('login_channel')
            ->distinct()->pluck('login_channel')->all();
        $result = [];
        foreach (array_unique(array_merge($values, $historical)) as $value) {$result[$value] = WebChannels::label($value);}
        return $result;
    }

    public function players(int $uid)
    {
        // Historical snapshots stay attached to the original account after rename/transfer/deletion.
        return DB::table(Installer::PROFILES)->join(Installer::ENTRIES, Installer::ENTRIES.'.id', '=', Installer::PROFILES.'.entry_id')
            ->where(Installer::ENTRIES.'.user_id', $uid)->whereNotNull('player_id')
            ->where(Installer::ENTRIES.'.occurred_at', '>=', now('UTC')->subDays(Recorder::DAYS))
            ->select('player_id','player_name')->distinct()->get();
    }
}
