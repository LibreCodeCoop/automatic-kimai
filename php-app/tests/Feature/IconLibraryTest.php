<?php
declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

final class IconLibraryTest extends TestCase
{
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
        self::assertGreaterThanOrEqual(20,$symbols->length);
        $names=[];
        foreach ($symbols as $element) {
            $names[$element->getAttribute('id')]=true;
            self::assertSame('0 0 24 24',$element->getAttribute('viewBox'));
        }
        foreach (['cloud','send','git-pull-request','panel-left-close',
            'panel-left-open','database','sun','moon','clock-3'] as $key) {
            self::assertArrayHasKey($key,$names);
        }

        $page=$this->get('http://localhost/')->assertOk();
        $page->assertSee('/icons/lucide.svg',false)
            ->assertDontSee('>☁</div>',false)
            ->assertDontSee('>⌘</div>',false)
            ->assertDontSee('>✈</div>',false);
    }
}
