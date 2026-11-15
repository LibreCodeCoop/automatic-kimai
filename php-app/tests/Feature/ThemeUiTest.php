<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression: Dark Mode must not mix light-mode alerts and fields with
 * dark backgrounds. Every semantic text/background pair meets WCAG AA.
 */
final class ThemeUiTest extends TestCase
{
    public function test_user_can_choose_system_light_or_dark_without_server_secrets(): void
    {
        $this->get('http://localhost/')->assertOk()
            ->assertSee('id="theme-select"',false)
            ->assertSee('<option value="system">Sistema</option>',false)
            ->assertSee('<option value="light">Claro</option>',false)
            ->assertSee('<option value="dark">Escuro</option>',false)
            ->assertSee('automatic-kimai:theme')
            ->assertSee('css/automatic-kimai.css')
            ->assertSee('id="telegram-setup"',false);
    }

    private static function variables(string $stylesheet,string $selector): array
    {
        $selectorPattern=preg_quote($selector,'/');
        self::assertSame(1,preg_match('/'. $selectorPattern.'\s*\{([^{}]*)\}/s',$stylesheet,$block));
        preg_match_all('/(--[a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/',$block[1],$colors,PREG_SET_ORDER);
        $result=[];
        foreach ($colors as $token) $result[$token[1]]=$token[2];
        return $result;
    }

    private static function luminance(string $hex): float
    {
        $r=[];
        foreach ([1,3,5] as $offset) {
            $value=hexdec(substr($hex,$offset,2))/255;
            $r[]=$value<=0.04045 ? $value/12.92 : (($value+0.055)/1.055)**2.4;
        }
        return 0.2126*$r[0]+0.7152*$r[1]+0.0722*$r[2];
    }

    private static function contrast(string $a,string $b): float
    {
        $first=self::luminance($a);
        $second=self::luminance($b);
        return (max($first,$second)+0.05)/(min($first,$second)+0.05);
    }

    public function test_all_semantic_palette_text_pairs_meet_wcag_aa_in_both_modes(): void
    {
        $stylesheet=file_get_contents(public_path('css/automatic-kimai.css'));
        self::assertIsString($stylesheet);
        $light=self::variables($stylesheet,':root');
        $dark=self::variables($stylesheet,':root[data-theme="dark"]');
        $systemDark=self::variables($stylesheet,':root:not([data-theme])');
        self::assertSame($dark,$systemDark,'System dark palette should match selected dark palette');

        $pairs=[
            ['--fg','--card'],['--fg','--bg'],
            ['--muted','--card'],['--field-fg','--field-bg'],
            ['--placeholder','--field-bg'],['--primary-fg','--primary-bg'],
            ['--secondary-fg','--secondary-bg'],['--notice-fg','--notice-bg'],
            ['--error-fg','--error-bg'],['--warn-fg','--card'],
            ['--header-fg','--header-bg'],['--header-sub','--header-bg'],
        ];
        foreach (['light'=>$light,'dark'=>$dark] as $name=>$tokens) {
            foreach ($pairs as [$text,$background]) {
                self::assertArrayHasKey($text,$tokens);
                self::assertArrayHasKey($background,$tokens);
                self::assertGreaterThanOrEqual(
                    4.5,self::contrast($tokens[$text],$tokens[$background]),
                    "$name theme contrast of $text over $background must be >= 4.5"
                );
            }
        }
    }

    public function test_ui_widgets_use_semantic_colors_and_dark_native_controls(): void
    {
        $stylesheet=file_get_contents(public_path('css/automatic-kimai.css'));
        self::assertIsString($stylesheet);
        foreach ([
            '.notice.error','button:disabled','input::placeholder',
            'select option','details[open]',':focus-visible',
            'color-scheme: dark','--line-strong',
        ] as $rule) {
            self::assertStringContainsString($rule,$stylesheet);
        }
        self::assertStringNotContainsString('background:#fff', $stylesheet);
    }
}
