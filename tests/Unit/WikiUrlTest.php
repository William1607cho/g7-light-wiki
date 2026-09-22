<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

class WikiUrlTest extends TestCase
{
    public function test_목록_주소는_상대_경로다(): void
    {
        $this->assertSame('/board/test-wiki', WikiUrl::boardList('test-wiki'));
        $this->assertStringStartsWith('/', WikiUrl::boardList('test-wiki', WikiUrl::SORT_RECENT));
        $this->assertStringNotContainsString('http', WikiUrl::boardList('test-wiki', WikiUrl::SORT_RANDOM));
    }

    public function test_허용된_정렬_두_가지만_주소에_실린다(): void
    {
        $this->assertSame(
            '/board/test-wiki?sort_by=g7lw-recent',
            WikiUrl::boardList('test-wiki', WikiUrl::SORT_RECENT)
        );
        $this->assertSame(
            '/board/test-wiki?sort_by=g7lw-random',
            WikiUrl::boardList('test-wiki', WikiUrl::SORT_RANDOM)
        );
    }

    public function test_허용_밖_정렬은_무시하고_정렬_없는_목록을_준다(): void
    {
        foreach (['', 'id', 'g7lw-', 'g7lw-recent2', 'G7LW-RECENT', 'created_at', '../etc'] as $bad) {
            $this->assertSame(
                '/board/test-wiki',
                WikiUrl::boardList('test-wiki', $bad),
                "허용 밖 정렬 '{$bad}' 이 주소에 실렸다"
            );
        }
    }

    public function test_slug_는_인코딩해_붙인다(): void
    {
        $this->assertSame('/board/a%2Fb', WikiUrl::boardList('a/b'));
        $this->assertSame(
            '/board/%EC%9C%84%ED%82%A4?sort_by=g7lw-recent',
            WikiUrl::boardList('위키', WikiUrl::SORT_RECENT)
        );
    }

    public function test_정렬_값은_코어_화이트리스트에_얹는_이름이다(): void
    {
        // 템플릿이 API 로 넘기는 파라미터 이름은 `sort_by` 하나뿐이다. 값 이름이 바뀌면
        // 이후 묶음의 목록 모드가 못 알아보므로 값 자체를 고정한다.
        $this->assertSame('g7lw-recent', WikiUrl::SORT_RECENT);
        $this->assertSame('g7lw-random', WikiUrl::SORT_RANDOM);
    }
}
