<?php

namespace Sd1\IamSso\Jwt;

use Sd1\IamSso\Exceptions\InvalidTokenException;

/**
 * Verifikasi JWT RS256 tanpa library tambahan (openssl_verify bawaan PHP).
 * Hanya RS256 yang diterima (menolak "none"/HS256 untuk mencegah algorithm confusion).
 */
class JwtVerifier
{
    /** @var PublicKeyProvider */
    private $keys;

    /** @var array */
    private $config;

    /** @var string */
    private $audience;

    public function __construct(PublicKeyProvider $keys, array $jwtConfig, string $audience)
    {
        $this->keys = $keys;
        $this->config = $jwtConfig;
        $this->audience = $audience;
    }

    /**
     * @return array klaim
     *
     * @throws InvalidTokenException
     */
    public function verify(string $jwt, $now = null): array
    {
        $now = $now === null ? time() : (int) $now;
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Format JWT tidak valid.');
        }
        list($h64, $p64, $s64) = $parts;

        $header = json_decode($this->b64($h64), true);
        $claims = json_decode($this->b64($p64), true);
        $signature = $this->b64($s64);
        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            throw new InvalidTokenException('JWT tidak dapat dibaca.');
        }
        if (! isset($header['alg']) || $header['alg'] !== 'RS256') {
            throw new InvalidTokenException('Algoritma JWT harus RS256.');
        }

        if (! $this->signatureValid($h64 . '.' . $p64, $signature, false)) {
            // Kunci IAM mungkin diganti: ambil ulang sekali bila kunci berasal dari IAM.
            if (! $this->keys->isRemote() || ! $this->signatureValid($h64 . '.' . $p64, $signature, true)) {
                throw new InvalidTokenException('Tanda tangan JWT tidak valid.');
            }
        }

        $leeway = (int) (isset($this->config['leeway']) ? $this->config['leeway'] : 60);
        if (! isset($claims['exp']) || $now - $leeway >= (int) $claims['exp']) {
            throw new InvalidTokenException('JWT sudah kedaluwarsa.');
        }
        if (isset($claims['nbf']) && $now + $leeway < (int) $claims['nbf']) {
            throw new InvalidTokenException('JWT belum berlaku.');
        }
        if (isset($claims['iat']) && $now + $leeway < (int) $claims['iat']) {
            throw new InvalidTokenException('JWT diterbitkan di masa depan.');
        }
        if (! empty($this->config['issuer']) && (! isset($claims['iss']) || $claims['iss'] !== $this->config['issuer'])) {
            throw new InvalidTokenException('Penerbit (iss) JWT tidak sesuai.');
        }
        $aud = isset($claims['aud']) ? (array) $claims['aud'] : [];
        if (! in_array($this->audience, array_map('strval', $aud), true)) {
            throw new InvalidTokenException('JWT bukan untuk aplikasi ini (aud).');
        }

        return $claims;
    }

    private function signatureValid(string $input, string $signature, bool $freshKey): bool
    {
        $key = openssl_pkey_get_public($this->keys->get($freshKey));
        if ($key === false) {
            throw new InvalidTokenException('Public key IAM tidak valid.');
        }

        return openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function b64(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
