<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiIndexBuilder;

class WikiIndexBuilderTest extends TestCase
{
    /**
     * @param  list<string>  $norms
     * @return list<array{id: int, title: string, title_norm: string}>
     */
    private function docs(array $norms): array
    {
        $docs = [];

        foreach ($norms as $i => $norm) {
            $docs[] = ['id' => $i + 1, 'title' => $norm, 'title_norm' => $norm];
        }

        return $docs;
    }

    public function test_한글은_초성으로_묶인다(): void
    {
        $this->assertSame('ㄱ', WikiIndexBuilder::bucketOf('가나다'));
        $this->assertSame('ㅎ', WikiIndexBuilder::bucketOf('하늘'));
    }

    public function test_쌍자음은_기본_자음에_합쳐진다(): void
    {
        $this->assertSame('ㄱ', WikiIndexBuilder::bucketOf('까치'));
        $this->assertSame('ㄷ', WikiIndexBuilder::bucketOf('딸기'));
        $this->assertSame('ㅂ', WikiIndexBuilder::bucketOf('빵'));
        $this->assertSame('ㅅ', WikiIndexBuilder::bucketOf('싸움'));
        $this->assertSame('ㅈ', WikiIndexBuilder::bucketOf('짜장'));
    }

    public function test_라틴과_숫자와_기타로_묶인다(): void
    {
        $this->assertSame('A', WikiIndexBuilder::bucketOf('apple'));
        $this->assertSame('Z', WikiIndexBuilder::bucketOf('zebra'));
        $this->assertSame('7', WikiIndexBuilder::bucketOf('7회차'));
        $this->assertSame(WikiIndexBuilder::OTHER, WikiIndexBuilder::bucketOf('漢字'));
        $this->assertSame(WikiIndexBuilder::OTHER, WikiIndexBuilder::bucketOf('😀'));
        $this->assertSame(WikiIndexBuilder::OTHER, WikiIndexBuilder::bucketOf(''));
    }

    public function test_묶음_순서는_초성_라틴_숫자_기타다(): void
    {
        $groups = WikiIndexBuilder::build($this->docs(['😀', '7회차', 'apple', '하늘', '가나다']));

        $this->assertSame(
            ['ㄱ', 'ㅎ', 'A', '7', WikiIndexBuilder::OTHER],
            array_map(static fn (array $g): string => $g['label'], $groups)
        );
    }

    public function test_묶음_안은_코드포인트_순이다(): void
    {
        $groups = WikiIndexBuilder::build($this->docs(['가나다', '가가가', '갸륵']));

        $this->assertSame(
            ['가가가', '가나다', '갸륵'],
            array_map(static fn (array $d): string => $d['title_norm'], $groups[0]['items'])
        );
    }

    public function test_비어_있는_묶음은_결과에_없다(): void
    {
        $groups = WikiIndexBuilder::build($this->docs(['apple']));

        $this->assertCount(1, $groups);
        $this->assertSame('A', $groups[0]['label']);
    }

    public function test_문서가_없으면_빈_배열이다(): void
    {
        $this->assertSame([], WikiIndexBuilder::build([]));
    }
}
