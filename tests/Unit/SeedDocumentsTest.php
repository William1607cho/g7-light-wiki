<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\SeedDocuments;

/**
 * 시드 문서 3건 — 제목·순서·본문.
 */
class SeedDocumentsTest extends TestCase
{
    public function test_세_건을_정해진_순서로_만든다(): void
    {
        $keys = array_column(SeedDocuments::all(), 'key');

        $this->assertSame([SeedDocuments::INDEX, SeedDocuments::SYNTAX, SeedDocuments::FRONT], $keys);
    }

    public function test_제목(): void
    {
        $titles = array_column(SeedDocuments::all(), 'title', 'key');

        $this->assertSame('모든 문서', $titles[SeedDocuments::INDEX]);
        $this->assertSame('위키 문법 도움말', $titles[SeedDocuments::SYNTAX]);
        $this->assertSame('대문', $titles[SeedDocuments::FRONT]);
    }

    public function test_모두_HTML_모드다(): void
    {
        foreach (SeedDocuments::all() as $seed) {
            $this->assertSame('html', $seed['content_mode']);
        }
    }

    public function test_대문은_인사말_둘러보기_두_문서_링크(): void
    {
        $front = SeedDocuments::body(SeedDocuments::FRONT);

        $this->assertStringContainsString('<p>[[#둘러보기]]</p>', $front);
        $this->assertStringContainsString('[[모든 문서]]', $front);
        $this->assertStringContainsString('[[위키 문법 도움말]]', $front);
        // 인사말 한 줄 + 둘러보기 + 링크 줄
        $this->assertSame(3, substr_count($front, '<p>'));
    }

    public function test_대문_링크는_다른_시드의_제목과_글자가_같다(): void
    {
        $front = SeedDocuments::body(SeedDocuments::FRONT);

        $this->assertStringContainsString('[['.SeedDocuments::TITLES[SeedDocuments::INDEX].']]', $front);
        $this->assertStringContainsString('[['.SeedDocuments::TITLES[SeedDocuments::SYNTAX].']]', $front);
    }

    public function test_모든_문서는_색인_한_줄(): void
    {
        $this->assertSame('<p>[[#색인]]</p>', SeedDocuments::body(SeedDocuments::INDEX));
    }

    public function test_문법_문서는_자리만_있다(): void
    {
        $syntax = SeedDocuments::body(SeedDocuments::SYNTAX);

        $this->assertStringNotContainsString('[[', $syntax);
        $this->assertStringContainsString('곧 채워집니다', $syntax);
    }

    public function test_검색창_폼과_번역_토큰이_없다(): void
    {
        foreach (SeedDocuments::all() as $seed) {
            $this->assertStringNotContainsString('<form', $seed['content']);
            $this->assertStringNotContainsString('<input', $seed['content']);
            $this->assertStringNotContainsString('$t:', $seed['content']);
        }
    }

    public function test_모르는_시드는_예외(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SeedDocuments::body('nope');
    }
}
