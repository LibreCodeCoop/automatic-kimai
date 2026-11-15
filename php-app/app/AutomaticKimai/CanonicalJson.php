<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

/**
 * Stable Python json.dumps(sort_keys=True) format for seamless migration of
 * SHA-256 session IDs, evidence digests and SQLite ledger identity keys.
 * Never encode tokens or private user data for logging.
 */
final class CanonicalJson
{
    public static function encode(mixed $value,bool $ensureAscii=true): string
    {
        $flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION;
        if (!$ensureAscii)$flags|=JSON_UNESCAPED_UNICODE;
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(', ',array_map(
                    static fn($x)=>self::encode($x,$ensureAscii),$value)).']';
            }
            $keys=array_keys($value);
            sort($keys,SORT_STRING);
            $parts=[];
            foreach ($keys as $key) {
                $parts[]=json_encode((string)$key,$flags).': '.self::encode($value[$key],$ensureAscii);
            }
            return '{'.implode(', ',$parts).'}';
        }
        return json_encode($value,$flags);
    }

    public static function digest(mixed $value,bool $ensureAscii=true): string
    {
        return hash('sha256',self::encode($value,$ensureAscii));
    }
}
