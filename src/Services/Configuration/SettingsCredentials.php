<?php

namespace MiningManager\Services\Configuration;

/**
 * Locks the credentials in a settings export with a passphrase the operator
 * chooses, so a file that travels between installs never carries webhook URLs
 * or API keys readable. Nothing about the install goes into the key, so the
 * file opens on any install where the passphrase is known, and nowhere else.
 */
class SettingsCredentials
{
    public const CIPHER = 'aes-256-gcm';

    public const KDF = 'pbkdf2-sha256';

    /**
     * Every guess at the passphrase costs this many rounds. 600,000 is
     * OWASP's figure for PBKDF2 with SHA-256.
     */
    public const ROUNDS = 600000;

    /**
     * A file names its own count, so a crafted one could ask for enough to
     * tie up the request. Anything above this is refused unread.
     */
    public const MAX_ROUNDS = 5000000;

    public const MIN_PASSPHRASE = 8;

    /**
     * Bound into the block, so it opens only as what it was locked as.
     */
    private const CONTEXT = 'mining-manager settings credentials';

    /**
     * @param array $credentials
     * @param string $passphrase
     * @return array{cipher: string, kdf: string, rounds: int, salt: string, iv: string, tag: string, data: string}
     */
    public static function seal(array $credentials, string $passphrase): array
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $tag = '';

        $data = openssl_encrypt(
            json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::CIPHER,
            self::key($passphrase, $salt, self::ROUNDS),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::CONTEXT
        );

        if ($data === false) {
            throw new \RuntimeException('The webhook URLs and API keys could not be locked.');
        }

        return [
            'cipher' => self::CIPHER,
            'kdf' => self::KDF,
            'rounds' => self::ROUNDS,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($data),
        ];
    }

    /**
     * What seal() locked, or null when the passphrase does not open it.
     *
     * @throws \InvalidArgumentException when the block is not one seal() wrote
     */
    public static function open(array $sealed, string $passphrase): ?array
    {
        $rounds = $sealed['rounds'] ?? null;
        $parts = [];
        foreach (['salt', 'iv', 'tag', 'data'] as $part) {
            $parts[$part] = is_string($sealed[$part] ?? null) ? base64_decode($sealed[$part], true) : false;
        }

        if (($sealed['cipher'] ?? null) !== self::CIPHER
            || ($sealed['kdf'] ?? null) !== self::KDF
            || !is_int($rounds) || $rounds < 1 || $rounds > self::MAX_ROUNDS
            || in_array(false, $parts, true)
            || strlen($parts['salt']) < 16 || strlen($parts['iv']) !== 12 || strlen($parts['tag']) !== 16) {
            throw new \InvalidArgumentException('The webhook URLs and API keys in this file are damaged, so nothing was imported.');
        }

        $plain = openssl_decrypt(
            $parts['data'],
            self::CIPHER,
            self::key($passphrase, $parts['salt'], $rounds),
            OPENSSL_RAW_DATA,
            $parts['iv'],
            $parts['tag'],
            self::CONTEXT
        );

        if ($plain === false) {
            return null;
        }

        $credentials = json_decode($plain, true);

        return is_array($credentials) ? $credentials : null;
    }

    private static function key(string $passphrase, string $salt, int $rounds): string
    {
        return hash_pbkdf2('sha256', $passphrase, $salt, $rounds, 32, true);
    }
}
