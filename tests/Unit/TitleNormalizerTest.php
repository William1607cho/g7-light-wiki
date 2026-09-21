<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;

class TitleNormalizerTest extends TestCase
{
    public function test_라틴_문자는_소문자로_모인다(): void
    {
        $this->assertSame('install guide', TitleNormalizer::normalize('Install Guide'));
        $this->assertSame('install guide', TitleNormalizer::normalize('INSTALL GUIDE'));
    }

    public function test_전각_ascii_는_반각으로_접힌다(): void
    {
        // Ａ Ｂ Ｃ (U+FF21~) → a b c
        $this->assertSame('abc', TitleNormalizer::normalize('ＡＢＣ'));
        $this->assertSame('g7-wiki', TitleNormalizer::normalize('Ｇ７－ＷＩＫＩ'));
    }

    public function test_앞뒤_공백과_연속_공백이_한_칸으로_모인다(): void
    {
        $this->assertSame('설치 방법', TitleNormalizer::normalize('  설치   방법  '));
        // NBSP(U+00A0)·전각 공백(U+3000)도 공백이다.
        $this->assertSame('설치 방법', TitleNormalizer::normalize("설치\u{00A0}방법"));
        $this->assertSame('설치 방법', TitleNormalizer::normalize("설치\u{3000}\u{3000}방법"));
    }

    public function test_한글은_바뀌지_않는다(): void
    {
        $this->assertSame('설치 방법', TitleNormalizer::normalize('설치 방법'));
    }

    public function test_한글_호환_자모는_NFKC_와_달리_그대로_남는다(): void
    {
        // NFKC 였다면 조합용 자모로 바뀐다. NFC 는 그대로 둔다.
        $this->assertSame("\u{3131}", TitleNormalizer::normalize("\u{3131}"));
    }

    public function test_라틴_밖_문자는_소문자화하지_않는다(): void
    {
        // 그리스 대문자 Σ 는 라틴이 아니므로 건드리지 않는다.
        $this->assertSame("\u{03A3}", TitleNormalizer::normalize("\u{03A3}"));
    }

    public function test_악센트는_구별한다(): void
    {
        // DB collation(utf8mb4_unicode_ci)과 달리 악센트를 지우지 않는다.
        $this->assertNotSame(
            TitleNormalizer::normalize('cafe'),
            TitleNormalizer::normalize('café')
        );
    }

    public function test_이모지는_서로_구별한다(): void
    {
        // utf8mb4_unicode_ci 에서는 모든 이모지가 같다. 정규화는 바이트를 남긴다.
        $this->assertNotSame(
            TitleNormalizer::normalize('문서 😀'),
            TitleNormalizer::normalize('문서 😁')
        );
    }

    public function test_빈_제목은_등록할_수_없다(): void
    {
        $this->assertFalse(TitleNormalizer::isRegistrable(TitleNormalizer::normalize('   ')));
        $this->assertFalse(TitleNormalizer::isRegistrable(TitleNormalizer::normalize("\u{00A0}")));
    }

    public function test_200자를_넘는_제목은_등록할_수_없다(): void
    {
        $this->assertTrue(TitleNormalizer::isRegistrable(str_repeat('가', 200)));
        $this->assertFalse(TitleNormalizer::isRegistrable(str_repeat('가', 201)));
    }
}
