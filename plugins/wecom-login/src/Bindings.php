<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class Bindings
{
    public function identityHash(array $member): string
    {
        return hash('sha256', strtolower($member['corp_id'])."\0".strtolower($member['member_id']));
    }

    public function forUser(int $uid): ?object
    {
        return DB::table(Installer::BINDINGS)->where('user_id', $uid)->first();
    }

    public function bind(User $user, array $member): void
    {
        try {
            DB::transaction(function () use ($user, $member): void {
                $locked = User::where('uid', $user->uid)->lockForUpdate()->first();
                if (!$locked || $locked->permission < User::NORMAL) {
                    throw new Failure('当前账号不可绑定企业微信。', 403);
                }
                $existing = $this->forUser($locked->uid);
                $hash = $this->identityHash($member);
                if ($existing) {
                    if (hash_equals($existing->identity_hash, $hash)) {
                        return;
                    }
                    throw new Failure('当前皮肤站账号已有绑定，请先解绑再绑定其他企业微信。', 409);
                }
                $owner = DB::table(Installer::BINDINGS)->where('identity_hash', $hash)->first();
                if ($owner) {
                    throw new Failure('该企业微信已绑定其他皮肤站账号，不能重复绑定。', 409);
                }
                DB::table(Installer::BINDINGS)->insert(array_merge($member, [
                    'user_id' => $locked->uid, 'identity_hash' => $hash, 'created_at' => now(),
                ]));
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new Failure('绑定关系已变化或该企业微信已被绑定，请刷新后重试。', 409);
            }
            throw $exception;
        }
    }

    public function loginUser(array $member): User
    {
        $hash = $this->identityHash($member);
        $binding = DB::table(Installer::BINDINGS)->where('identity_hash', $hash)->first();
        if (!$binding) {
            throw new Failure('此企业微信尚未绑定账号。请先使用原有方式登录皮肤站，再进入“企业微信绑定”页面。', 403);
        }
        // Keep lock ordering consistent with bind/unbind and re-read the binding.
        return DB::transaction(function () use ($binding, $hash): User {
            $user = User::where('uid', $binding->user_id)->lockForUpdate()->first();
            $current = DB::table(Installer::BINDINGS)->where('identity_hash', $hash)
                ->where('user_id', $binding->user_id)->lockForUpdate()->first();
            if (!$user || !$current || $user->permission < User::NORMAL) {
                throw new Failure('绑定账号已停用、删除或解绑，无法登录。', 403);
            }
            return $user;
        });
    }

    public function unbind(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $locked = User::where('uid', $user->uid)->lockForUpdate()->first();
            if (!$locked || $locked->permission < User::NORMAL) {
                throw new Failure('当前账号不可进行此操作。', 403);
            }
            if ($locked->password === '' || !$locked->verifyPassword($password)) {
                throw new Failure('皮肤站密码验证失败。尚未设置密码的账号请先设置密码，再解绑。', 403);
            }
            DB::table(Installer::BINDINGS)->where('user_id', $locked->uid)->delete();
        });
    }
}
