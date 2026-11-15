<?php
declare(strict_types=1);

namespace Tests;

use App\AutomaticKimai\LocalStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Feature tests must never read or write the real desktop profile.
 *
 * In particular, phpunit in a root-run Docker container must not replace
 * sources.json with a root-owned 0600 file: that causes the local desktop
 * backend (UID 1000) to return HTTP 500 and prevents the launcher from opening.
 */
abstract class TestCase extends BaseTestCase
{
    private ?string $isolatedLocalRoot=null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedLocalRoot=rtrim(sys_get_temp_dir(),'/')
            .'/automatic-kimai-feature-test-'.bin2hex(random_bytes(12));
        $this->app->instance(
            LocalStore::class,
            new LocalStore($this->isolatedLocalRoot),
        );
        // Laravel's Blade cache must also remain outside the mounted real
        // desktop storage when tests are run as root inside Docker.
        $views=$this->isolatedLocalRoot.'/views';
        if (!mkdir($views,0700)) {
            throw new \RuntimeException('Unable to create isolated Blade test cache');
        }
        config()->set('view.compiled',$views);
    }

    protected function tearDown(): void
    {
        try {
            $root=$this->isolatedLocalRoot;
            // Only remove the uniquely generated fixture; never follow a link
            // or remove the actual storage/app/private/automatic-kimai directory.
            if ($root!==null && !is_link($root) && is_dir($root)
                && str_starts_with(basename($root),'automatic-kimai-feature-test-')
                && dirname($root)===rtrim(sys_get_temp_dir(),'/')) {
                $views=$root.'/views';
                if (is_dir($views) && !is_link($views)) {
                    foreach (glob($views.'/*')?:[] as $path) {
                        if (is_file($path)||is_link($path))@unlink($path);
                    }
                    @rmdir($views);
                }
                foreach (glob($root.'/*')?:[] as $path) {
                    if (is_file($path)||is_link($path))@unlink($path);
                }
                @rmdir($root);
            }
        } finally {
            parent::tearDown();
        }
    }
}
