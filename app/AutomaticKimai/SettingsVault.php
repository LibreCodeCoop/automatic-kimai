<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/**
 * Portable, passphrase-derived E2EE envelope. Never upload an APP_KEY,
 * Nextcloud credential, MTProto session, database, or decrypted configuration.
 */
final class SettingsVault
{
    private const VERSION=1;
    private const CONTEXT='automatic-kimai.settings-sync.v1';
    private const MAX_BYTES=250_000;
    private const MEM=67_108_864;
    private const OPS=3;

    public static function generateSalt(): string
    {
        return random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }

    public static function derive(string $passphrase,string $salt): string
    {
        if (mb_strlen($passphrase)<16 || strlen($passphrase)>1024) {
            throw new RuntimeException('Use uma senha de recuperação exclusiva com pelo menos 16 caracteres.');
        }
        if (strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('Formato de backup inválido.');
        }
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $passphrase,$salt,self::OPS,self::MEM,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    private static function aad(string $scope): string
    {
        if (strlen($scope)!==64 || !ctype_xdigit($scope))throw new RuntimeException('Identidade Nextcloud inválida.');
        return self::CONTEXT.':'.strtolower($scope);
    }

    public static function seal(array $settings,string $key,string $salt,string $scope): string
    {
        if (strlen($key)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES ||
            strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('Chave do backup inválida.');
        }
        $plaintext=CanonicalJson::encode($settings,false);
        if (strlen($plaintext)>100_000)throw new RuntimeException('Configuração excede o limite de backup.');
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $encrypted=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,self::aad($scope),$nonce,$key
        );
        $envelope=[
            'version'=>self::VERSION,'algorithm'=>'xchacha20poly1305-ietf',
            'kdf'=>'argon2id-v1','salt'=>base64_encode($salt),
            'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($encrypted),
        ];
        $json=json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (strlen($json)>self::MAX_BYTES)throw new RuntimeException('Backup excede o limite.');
        return $json;
    }

    public static function salt(string $raw): string
    {
        $envelope=self::envelope($raw);
        return $envelope['salt'];
    }

    private static function envelope(string $raw): array
    {
        if (strlen($raw)>self::MAX_BYTES)throw new RuntimeException('Backup remoto excede o limite.');
        try {$doc=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}
        catch (\Throwable) {throw new RuntimeException('Backup criptografado inválido.');}
        if (!is_array($doc) || array_keys($doc)!==[
            'version','algorithm','kdf','salt','nonce','ciphertext'
        ] || ($doc['version']??null)!==self::VERSION ||
            ($doc['algorithm']??null)!=='xchacha20poly1305-ietf' ||
            ($doc['kdf']??null)!=='argon2id-v1') {
            throw new RuntimeException('Formato ou versão de backup não suportado.');
        }
        foreach (['salt','nonce','ciphertext'] as $field) {
            if (!is_string($doc[$field])||strlen($doc[$field])>self::MAX_BYTES) {
                throw new RuntimeException('Backup criptografado inválido.');
            }
            $decoded=base64_decode($doc[$field],true);
            if ($decoded===false)throw new RuntimeException('Backup criptografado inválido.');
            $doc[$field]=$decoded;
        }
        if (strlen($doc['salt'])!==SODIUM_CRYPTO_PWHASH_SALTBYTES ||
            strlen($doc['nonce'])!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ||
            strlen($doc['ciphertext'])<SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new RuntimeException('Backup criptografado inválido.');
        }
        return $doc;
    }

    public static function open(string $raw,string $key,string $scope): array
    {
        if (strlen($key)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Chave do backup inválida.');
        }
        $doc=self::envelope($raw);
        try {$clear=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $doc['ciphertext'],self::aad($scope),$doc['nonce'],$key
        );}
        catch (\Throwable) {$clear=false;}
        if ($clear===false)throw new RuntimeException('Senha de recuperação incorreta ou backup incompatível.');
        try {$value=json_decode($clear,true,32,JSON_THROW_ON_ERROR);}
        catch (\Throwable) {throw new RuntimeException('Dados do backup inválidos.');}
        if (!is_array($value)||($value['schema']??null)!==1) {
            throw new RuntimeException('Versão dos dados não suportada.');
        }
        return $value;
    }

    public static function unlock(string $raw,string $passphrase,string $scope): array
    {
        $salt=self::salt($raw);
        $key=self::derive($passphrase,$salt);
        try {$value=self::open($raw,$key,$scope);}
        catch (\Throwable $error) {
            sodium_memzero($key);
            throw $error;
        }
        return [$value,$key,$salt];
    }
}
