<?php
declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

final class IconLibraryTest extends TestCase
{
    public function test_service_brand_marks_use_local_simple_icons_not_lucide_symbols(): void
    {
        $sprite=public_path('icons/brands.svg');
        self::assertFileExists($sprite);
        self::assertFileExists(public_path('icons/SIMPLE-ICONS-LICENSE.md'));
        self::assertFileExists(public_path('icons/SIMPLE-ICONS-DISCLAIMER.md'));
        $document=new DOMDocument();
        self::assertTrue($document->load($sprite,LIBXML_NONET));
        $x=new DOMXPath($document);
        $x->registerNamespace('svg','http://www.w3.org/2000/svg');
        foreach (['nextcloud','telegram','github'] as $name) {
            $symbols=$x->query('/svg:svg/svg:symbol[@id="'.$name.'"]');
            self::assertSame(1,$symbols->length);
            self::assertGreaterThan(0,$x->query('svg:path',$symbols[0])->length);
        }
        $store=$this->app->make(\App\AutomaticKimai\LocalStore::class);
        self::assertNotSame(
            storage_path('app/private/automatic-kimai'),
            $store->root(),
            'The icon rendering test must not alter the real desktop profile',
        );
        self::assertSame(
            $store->root().'/views',
            config('view.compiled'),
            'The Blade test cache must not use storage/framework/views',
        );
        $store->setSources([
            'nextcloud_calendar'=>true,'github'=>true,'telegram_setup'=>true,
        ]);
        foreach (['integrations','nextcloud'] as $view) {
            $response=$this->get('http://localhost/?view='.$view)->assertOk();
            $response->assertSee('/icons/brands.svg',false);
        }
        $page=$this->get('http://localhost/?view=integrations')->getContent();
        self::assertStringNotContainsString('lucide.svg#cloud',$page);
        self::assertStringNotContainsString('lucide.svg#send',$page);
        self::assertStringNotContainsString('lucide.svg#git-pull-request',$page);
    }

    public function test_only_official_locally_bundled_lucide_icons_are_used(): void
    {
        $sprite=public_path('icons/lucide.svg');
        self::assertFileExists($sprite);
        self::assertFileExists(public_path('icons/LUCIDE-LICENSE'));
        self::assertStringContainsString('ISC',file_get_contents(public_path('icons/LUCIDE-LICENSE')));
        $doc=new DOMDocument();
        self::assertTrue($doc->load($sprite,LIBXML_NONET));
        $query=new DOMXPath($doc);
        $query->registerNamespace('svg','http://www.w3.org/2000/svg');
        $symbols=$query->query('/svg:svg/svg:symbol');
        self::assertGreaterThanOrEqual(17,$symbols->length);
        $names=[];
        foreach ($symbols as $element) {
            $names[$element->getAttribute('id')]=true;
            self::assertSame('0 0 24 24',$element->getAttribute('viewBox'));
        }
        foreach (['panel-left-close','panel-left-open','database','sun','moon','clock-3'] as $key) {
            self::assertArrayHasKey($key,$names);
        }

        $page=$this->get('http://localhost/')->assertOk();
        $page->assertSee('/icons/lucide.svg',false)
            ->assertDontSee('>☁</div>',false)
            ->assertDontSee('>⌘</div>',false)
            ->assertDontSee('>✈</div>',false);
    }
}
