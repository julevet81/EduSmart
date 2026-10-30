<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;

/**
 * Streaming authenticated encryption for backup files (libsodium secretstream, key from the passphrase with Argon2id).
 * Layout: "EDUBK1" | salt(16) | stream header | { length:uint32 | chunk }*. A cut or altered file fails to decrypt.
 */
final class BackupCrypto
{
    private const MAGIC = 'EDUBK1';

    private const CHUNK = 65536;

    public static function encrypt(string $from, string $to, string $passphrase): void
    {
        $in = self::open($from, 'rb');
        $out = self::open($to, 'wb');

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key($passphrase, $salt));
        fwrite($out, self::MAGIC.$salt.$header);

        $chunk = (string) fread($in, self::CHUNK);

        while (true) {
            $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
            $last = $next === '' && feof($in);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($out, pack('N', strlen($cipher)).$cipher);

            if ($last) {
                break;
            }

            $chunk = $next;
        }

        fclose($in);
        fclose($out);
    }

    public static function decrypt(string $from, string $to, string $passphrase): void
    {
        $in = self::open($from, 'rb');
        $head = (string) fread($in, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (! str_starts_with($head, self::MAGIC) || strlen($head) < strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('This is not an encrypted backup.');
        }

        $salt = substr($head, strlen(self::MAGIC), SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($head, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES), self::key($passphrase, $salt));
        $out = self::open($to, 'wb');
        $final = false;

        while (! feof($in)) {
            $len = fread($in, 4);

            if ($len === '' || $len === false) {
                break;
            }

            $size = unpack('N', str_pad($len, 4, "\0"))[1];
            $cipher = (string) fread($in, $size);
            $result = strlen($cipher) === $size ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher) : false;

            if ($result === false) {
                fclose($out);
                @unlink($to);
                throw new RuntimeException('Wrong passphrase, or the backup is damaged.');
            }

            fwrite($out, $result[0]);
            $final = $result[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        fclose($in);
        fclose($out);

        if (! $final) {
            @unlink($to);
            throw new RuntimeException('The backup is truncated.');
        }
    }

    private static function key(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        return $handle !== false ? $handle : throw new RuntimeException("Cannot open {$path}.");
    }
}
