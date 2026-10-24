<?php

namespace Blessing\TwoFactor;

final class Totp
{
    public function secret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        foreach (str_split($bits, 5) as $b) {
            $out .= $alphabet[bindec(str_pad($b, 5, '0'))];
        }

        return $out;
    }

    public function code(string $secret, int $step, int $digits = 6): string
    {
        $bits = '';
        foreach (str_split($secret) as $c) {
            $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c);
            if ($n === false) {
                throw new \InvalidArgumentException('Invalid base32');
            }$bits .= str_pad(decbin($n), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $key .= chr(bindec($b));
            }
        }
        $mac = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), $key, true);
        $offset = ord($mac[19]) & 15;
        $number = unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function match(string $secret, string $code, int $last = -1, ?int $time = null): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/D', $code)) {
            return null;
        }
        $step = intdiv($time ?? time(), 30);
        $window = (int) app(Settings::class)->values()['totp_window'];
        for ($i = 0; $i <= $window; $i++) {
            foreach (array_unique([$step - $i, $step + $i]) as $n) {
                if ($n > $last && hash_equals($this->code($secret, $n), $code)) {
                    return $n;
                }
            }
        }

        return null;
    }
}
