<?php

namespace Tests\Unit;

use Plugins\G7\Light\Wiki\Models\WikiRef;
use Plugins\G7\Light\Wiki\Support\RefExtractor;
use PHPUnit\Framework\TestCase;

/**
 * 본문 → 표기 줄 추출.
 *
 * 추출은 표시 시점 치환과 **같은 파서·같은 순회**를 쓴다. 그래서 `<code>` 안이 빠지는 것,
 * 형식이 틀린 연표 키가 사건이 되지 않는 것이 여기서도 그대로 확인된다.
 */
class RefExtractorTest extends TestCase
{
    public function test_html_모드가_아니면_아무것도_뽑지_않는다(): void
    {
        $this->assertSame([], RefExtractor::extract('<p>[[문서]] [[분류:인물]]</p>', 'text'));
        $this->assertSame([], RefExtractor::extract('<p>[[문서]]</p>', 'markdown'));
    }

    public function test_표기가_없으면_빈_목록이다(): void
    {
        $this->assertSame([], RefExtractor::extract('<p>그냥 글이다</p>', 'html'));
        $this->assertSame([], RefExtractor::extract('', 'html'));
    }

    public function test_종류별로_뽑는다(): void
    {
        $rows = RefExtractor::extract(
            '<p>[[가나다]] [[분류:인물]] [[별칭:다른이름]] [[연표:1023.4|무슨 일]]</p>',
            'html'
        );

        $this->assertCount(4, $rows);

        $this->assertSame(WikiRef::KIND_LINK, $rows[0]['kind']);
        $this->assertSame('가나다', $rows[0]['target']);

        $this->assertSame(WikiRef::KIND_CATEGORY, $rows[1]['kind']);
        $this->assertSame('인물', $rows[1]['target'], '접두어는 target 에 담지 않는다');

        $this->assertSame(WikiRef::KIND_ALIAS, $rows[2]['kind']);
        $this->assertSame('다른이름', $rows[2]['target']);

        $this->assertSame(WikiRef::KIND_EVENT, $rows[3]['kind']);
        $this->assertSame('1023.4', $rows[3]['target']);
        $this->assertSame('무슨 일', $rows[3]['label']);
        $this->assertNotNull($rows[3]['sort_key']);
    }

    public function test_등장_순서가_seq_다(): void
    {
        $rows = RefExtractor::extract('<p>[[가]] [[나]]</p><p>[[다]]</p>', 'html');

        $this->assertSame([0, 1, 2], array_column($rows, 'seq'));
        $this->assertSame(['가', '나', '다'], array_column($rows, 'target'));
    }

    public function test_code_와_a_안의_표기는_뽑지_않는다(): void
    {
        $rows = RefExtractor::extract(
            '<p><code>[[코드안]]</code> <a href="/x">[[링크안]]</a> [[밖]]</p>',
            'html'
        );

        $this->assertSame(['밖'], array_column($rows, 'target'));
    }

    public function test_설명이_없는_사건은_키를_설명으로_쓴다(): void
    {
        $rows = RefExtractor::extract('<p>[[연표:777]]</p>', 'html');

        $this->assertCount(1, $rows);
        $this->assertSame('777', $rows[0]['target']);
        $this->assertSame('777', $rows[0]['label']);
    }

    public function test_형식이_틀린_연표_키는_사건이_되지_않는다(): void
    {
        $rows = RefExtractor::extract('<p>[[연표:어제|무슨 일]] [[연표:1|진짜]]</p>', 'html');

        $this->assertCount(1, $rows);
        $this->assertSame('1', $rows[0]['target']);
        $this->assertSame(0, $rows[0]['seq'], '등록되지 않은 표기는 seq 를 먹지 않는다');
    }

    public function test_접두어만_있고_이름이_없으면_표기가_아니다(): void
    {
        $this->assertSame([], RefExtractor::extract('<p>[[분류:]] [[별칭: ]]</p>', 'html'));
    }

    public function test_자리표시는_뽑지_않는다(): void
    {
        $this->assertSame([], RefExtractor::extract('<p>[[#색인]] [[#연표]] [[#분류|인물]]</p>', 'html'));
    }

    public function test_이름은_정규화되어_담긴다(): void
    {
        // 전각 ASCII·대문자·연속 공백이 접힌다.
        $rows = RefExtractor::extract('<p>[[ＡＢＣ  Ｄ]]</p>', 'html');

        $this->assertCount(1, $rows);
        $this->assertSame('abc d', $rows[0]['target_norm']);
        $this->assertSame('ＡＢＣ  Ｄ', $rows[0]['target'], '원문은 그대로 둔다');
    }
}
