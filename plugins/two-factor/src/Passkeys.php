<?php

namespace Blessing\TwoFactor;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use lbuchs\WebAuthn\WebAuthn;

final class Passkeys
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/D', $text) || strlen($text) > 140000) {
            throw new \RuntimeException('验证数据格式不正确。');
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        if ($raw === false || self::encode($raw) !== $text) {
            throw new \RuntimeException('验证数据格式不正确。');
        }

        return $raw;
    }

    private function server(): WebAuthn
    {
        $s = app(Settings::class);

        return new WebAuthn($s->values()['rp_name'], $s->rpId(), ['none'], true);
    }

    private function issue(string $purpose, ?User $user, object $options, WebAuthn $server): array
    {
        $values = app(Settings::class)->values();
        session(['two_factor.webauthn' => ['purpose' => $purpose, 'uid' => $user ? (int) $user->uid : null, 'at' => time(),
            'challenge' => self::encode($server->getChallenge()->getBinaryString()), 'origin' => app(Settings::class)->origin(),
            'policy' => app(Settings::class)->revision(), 'revision' => $user ? app(Accounts::class)->account((int) $user->uid)->revision : null,
            'uv' => $purpose === 'login' || $values['user_verification'] === 'required']]);

        return json_decode(json_encode($options), true);
    }

    public function createOptions(User $user): array
    {
        $values = app(Settings::class)->values();
        $server = $this->server();
        $ids = DB::table('two_factor_passkeys')->where('user_id', $user->uid)->pluck('credential_id')->map(fn ($id) => self::decode($id))->all();
        $attachment = match ($values['attachment']) {
            'platform' => false,'cross-platform' => true,default => null,
        };
        $options = $server->getCreateArgs(hex2bin(app(Accounts::class)->account((int) $user->uid)->handle), $user->email, $user->nickname ?: $user->email, $values['timeout'], true, $values['user_verification'], $attachment, $ids);

        return $this->issue('register', $user, $options, $server);
    }

    public function getOptions(string $purpose, ?User $user): array
    {
        $settings = app(Settings::class);
        $server = $this->server();
        $values = $settings->values();
        $ids = $user ? DB::table('two_factor_passkeys')->where('user_id', $user->uid)->where('rp_id', $settings->rpId())->pluck('credential_id')->map(fn ($id) => self::decode($id))->all() : [];
        if ($user && !$ids) {
            throw new \RuntimeException('此账号尚未添加可用的通行密钥。');
        }
        $options = $server->getGetArgs($ids, $values['timeout'], true, true, true, true, true, $purpose === 'login' ? 'required' : $values['user_verification']);

        return $this->issue($purpose, $user, $options, $server);
    }

    private function consume(string $purpose, ?User $user, array $input): array
    {
        $state = session()->pull('two_factor.webauthn');
        $settings = app(Settings::class);
        if (!$state || !hash_equals($state['policy'], app(Settings::class)->revision()) || $state['purpose'] !== $purpose || $state['uid'] !== ($user ? (int) $user->uid : null) || time() - $state['at'] > (int) $settings->values()['challenge_ttl'] || $state['origin'] !== $settings->origin()
            || ($user && !hash_equals($state['revision'], app(Accounts::class)->account((int) $user->uid)->revision))) {
            throw new \RuntimeException('通行密钥请求已过期，请重试。');
        }
        $client = self::decode($input['clientDataJSON']);
        $data = json_decode($client, true);
        if (!is_array($data) || ($data['origin'] ?? '') !== $settings->origin() || ($data['crossOrigin'] ?? false) !== false || isset($data['topOrigin']) || ($data['tokenBinding']['status'] ?? '') === 'present') {
            throw new \RuntimeException('通行密钥来源校验失败。');
        }

        return [$state, $client];
    }

    public function register(User $user, array $input): void
    {
        [$state,$client] = $this->consume('register', $user, $input);
        $values = app(Settings::class)->values();
        $server = $this->server();
        $data = $server->processCreate($client, self::decode($input['attestationObject']), self::decode($state['challenge']), $state['uv'], true);
        $id = $data->credentialId;
        $encoded = self::encode($id);
        if (!hash_equals($encoded, $input['id'])) {
            throw new \RuntimeException('通行密钥标识不一致。');
        }
        DB::transaction(function () use ($user, $input, $data, $encoded, $values) {
            DB::table('two_factor_accounts')->where('user_id', $user->uid)->lockForUpdate()->first();
            if (DB::table('two_factor_passkeys')->where('user_id', $user->uid)->count() >= $values['max_passkeys']) {
                throw new \RuntimeException('通行密钥数量已达到上限。');
            }
            if (DB::table('two_factor_passkeys')->where('credential_hash', hash('sha256', $encoded))->exists()) {
                throw new \RuntimeException('此通行密钥已添加。');
            }
            DB::table('two_factor_passkeys')->insert(['user_id' => $user->uid, 'name' => $input['name'], 'credential_id' => $encoded, 'credential_hash' => hash('sha256', $encoded),
                'public_key' => $data->credentialPublicKey, 'sign_count' => $data->signatureCounter ?? 0, 'rp_id' => app(Settings::class)->rpId(),
                'transports' => json_encode($input['transports'] ?? []), 'backup_eligible' => (bool) $data->isBackupEligible, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function verify(string $purpose, ?User $user, array $input): User
    {
        [$state,$client] = $this->consume($purpose, $user, $input);

        return DB::transaction(function () use ($purpose, $user, $input, $state, $client) {
            $key = DB::table('two_factor_passkeys')->where('credential_hash', hash('sha256', $input['id']))->lockForUpdate()->first();
            if (!$key || ($user && (int) $key->user_id !== (int) $user->uid) || $key->rp_id !== app(Settings::class)->rpId()) {
                throw new \RuntimeException('通行密钥验证未通过。');
            }
            $owner = User::find($key->user_id);
            if (!$owner || $owner->permission < User::NORMAL) {
                throw new \RuntimeException('通行密钥验证未通过。');
            }
            $handle = $input['userHandle'] ?? '';
            if (($purpose === 'login' && $handle === '') || ($handle !== '' && !hash_equals(hex2bin(app(Accounts::class)->account((int) $owner->uid)->handle), self::decode($handle)))) {
                throw new \RuntimeException('通行密钥账号不一致。');
            }
            $server = $this->server();
            $server->processGet($client, self::decode($input['authenticatorData']), self::decode($input['signature']), $key->public_key, self::decode($state['challenge']), (int) $key->sign_count, $state['uv'], true);
            DB::table('two_factor_passkeys')->where('id', $key->id)->update(['sign_count' => $server->getSignatureCounter() ?? 0, 'last_used_at' => now(), 'updated_at' => now()]);

            return $owner;
        });
    }
}
