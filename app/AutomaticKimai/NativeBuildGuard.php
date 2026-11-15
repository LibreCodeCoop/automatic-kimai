<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use FilesystemIterator;
use RuntimeException;

/**
 * NativePHP copies application files into an installable package without
 * honoring Git's ignore rules. Never build from an initialized user profile.
 *
 * This deliberately blocks end-user package creation until per-installation
 * key generation and a sanitized, isolated build directory are implemented.
 */
final class NativeBuildGuard
{
    public static function assertSafe(string $root): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $private = $root.'/storage/app/private';
        if (is_dir($private)) {
            foreach (new FilesystemIterator($private, FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->getFilename() === '.gitignore') {
                    continue;
                }
                throw new RuntimeException(
                    'Package blocked: private user data exists in this application copy. '.
                    'Build from an isolated clean source tree; do not publish the local profile.'
                );
            }
        }
        foreach (['.env.production','.env.local'] as $name) {
            if (is_file($root.'/'.$name)) {
                throw new RuntimeException(
                    'Package blocked: environment override is present; review a clean build tree.'
                );
            }
        }
        // A bundled APP_KEY is a shared secret, not a per-user credential.
        // Disallow until a first-launch key provisioning implementation exists.
        $env = $root.'/.env';
        if (is_file($env)) {
            $data = file_get_contents($env);
            if (!is_string($data)) {
                throw new RuntimeException('Package blocked: cannot inspect build environment');
            }
            if (preg_match('/^[ \t]*APP_KEY[ \t]*=[ \t]*([^\r\n]*)/m', $data, $found)
                && trim($found[1]) !== '' && !str_starts_with(trim($found[1]), '#')) {
                throw new RuntimeException(
                    'Package blocked: populated APP_KEY would be embedded into the installer. '.
                    'First-install key provisioning must be implemented before release builds.'
                );
            }
        }
        if (trim((string) getenv('APP_KEY')) !== '') {
            throw new RuntimeException(
                'Package blocked: APP_KEY supplied through the build environment.'
            );
        }
        foreach ([
            'database/database.sqlite',
            'storage/logs/laravel.log',
            'storage/app/private/automatic-kimai/secrets.enc',
        ] as $name) {
            if (is_file($root.'/'.$name)) {
                throw new RuntimeException('Package blocked: runtime storage has not been sanitized.');
            }
        }
    }
}
