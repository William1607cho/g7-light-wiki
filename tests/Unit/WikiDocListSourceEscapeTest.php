<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiDocListQuery;
use Plugins\G7\Light\Wiki\Support\WikiDocListSource;

/**
 * 목록 검색이 LIKE 와일드카드를 글자로 되돌리는지, 그리고 목록 응답의 개수 상한이
 * 자리표시 상한과 **따로** 서 있는지 본다.
 *
 * DB 를 타지 않는 순수 함수·상수만 시험한다 — 실제 조회는 스테이징 검증에서 잰다.
 */
class WikiDocListSourceEscapeTest extends TestCase
{
    public function test_와일드카드는_글자로_바뀐다(): void
    {
        $this->assertSame('\\%', WikiDocListSource::escapeLike('%'));
        $this->assertSame('\\_', WikiDocListSource::escapeLike('_'));
        $this->assertSame('a\\%b', WikiDocListSource::escapeLike('a%b'));
        $this->assertSame('a\\_b', WikiDocListSource::escapeLike('a_b'));
    }

    public function test_백슬래시가_먼저_처리된다(): void
    {
        // '\' 를 나중에 바꾸면 '%' 를 escape 하며 붙인 '\' 가 다시 escape 돼 '\\\%' 가 된다.
        $this->assertSame('\\\\', WikiDocListSource::escapeLike('\\'));
        $this->assertSame('\\\\\\%', WikiDocListSource::escapeLike('\\%'));
    }

    public function test_보통_글자는_그대로다(): void
    {
        $this->assertSame('설치 방법', WikiDocListSource::escapeLike('설치 방법'));
        $this->assertSame('apple pie', WikiDocListSource::escapeLike('apple pie'));
        $this->assertSame('', WikiDocListSource::escapeLike(''));
    }

    public function test_목록_상한은_이_클래스가_스스로_가진다(): void
    {
        // 전에는 목록 응답이 색인 자리표시의 상한(INDEX_LIMIT)을 빌려 썼다. 그러면 한쪽
        // 사정으로 값을 바꿀 때 다른 쪽이 조용히 따라 움직인다. 지금 값이 같은 것은 우연이다.
        $this->assertTrue(defined(WikiDocListSource::class.'::LIST_LIMIT'));
        $this->assertIsInt(WikiDocListSource::LIST_LIMIT);
        $this->assertGreaterThan(0, WikiDocListSource::LIST_LIMIT);

        $reflection = new \ReflectionClass(WikiDocListSource::class);
        $this->assertSame(
            WikiDocListSource::class,
            $reflection->getReflectionConstant('LIST_LIMIT')->getDeclaringClass()->getName()
        );
    }

    public function test_색인_상한은_따로_남아_있다(): void
    {
        // 자리표시 쪽 상한은 그대로다 — 이번 정리는 목록 응답이 그것을 빌려 쓰지 않게 한 것이다.
        $this->assertIsInt(WikiDocListQuery::INDEX_LIMIT);
    }

    public function test_무작위_개수는_10이다(): void
    {
        $this->assertSame(10, WikiDocListSource::RANDOM_COUNT);
        $this->assertLessThanOrEqual(WikiDocListSource::LIST_LIMIT, WikiDocListSource::RANDOM_COUNT);
    }
}
