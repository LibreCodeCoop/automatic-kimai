<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use PHPUnit\Framework\TestCase;

final class RepositoryLayoutTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_laravel_and_nativephp_share_the_repository_root(): void
    {
        $root=$this->root();
        foreach (['artisan','composer.json','.env.example','app','bootstrap',
            'config','database','routes','resources','storage','tests',
            'public','nativephp/electron/package.json',
            'desktop-preview/launch.sh','scripts/check-publication.php'] as $item) {
            self::assertFileExists($root.'/'.$item, $item.' must exist in root');
        }
        self::assertDirectoryDoesNotExist($root.'/php-app');
        self::assertFileDoesNotExist($root.'/PHP_MIGRATION.md');
        self::assertFileDoesNotExist($root.'/GUIA_INICIO_COOPERADOS.md');
    }

    public function test_public_documentation_and_workflows_use_the_new_layout(): void
    {
        $root=$this->root();
        foreach (['DEVELOPMENT.md','GETTING_STARTED.md','HOMOLOGATION.md',
            'RELEASE_CHECKLIST.md'] as $item) {
            self::assertFileExists($root.'/docs/'.$item);
        }
        $workflow=(string)file_get_contents($root.'/.github/workflows/php-ci.yml');
        self::assertStringNotContainsString('working-directory: php-app',$workflow);
        self::assertStringContainsString('working-directory: nativephp/electron',$workflow);
        self::assertStringContainsString('APP_PATH: ' . '$' . '{{ github.workspace }}',$workflow);
        self::assertStringContainsString('cache-dependency-path: nativephp/electron/package-lock.json',$workflow);
        self::assertStringNotContainsString('feat/php-migration',$workflow);
        $scanner=(string)file_get_contents($root.'/scripts/check-publication.php');
        self::assertStringContainsString('storage/app/private/',$scanner);
        self::assertStringContainsString('nativephp/electron/.npmrc',$scanner);
        self::assertStringNotContainsString('php-app/',$scanner);
    }
}
