<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiDocListQuery;
use Plugins\G7\Light\Wiki\Support\WikiBoardListQuery;

/**
 * 목록 검색이 LIKE 와일드카드를 글자로 되돌리는지, 그리고 목록 응답의 개수 상한이
 * 자리표시 상한과 **따로** 서 있는지 본다.
 *
 * DB 를 타지 않는 순수 함수·상수만 시험한다 — 실제 조회는 스테이징 검증에서 잰다.
 */
class WikiBoardListQueryTest extends TestCase
{
    public function test_와일드카드는_글자로_바뀐다(): void
    {
        $this->assertSame('\\%', WikiBoardListQuery::escapeLike('%'));
        $this->assertSame('\\_', WikiBoardListQuery::escapeLike('_'));
        $this->assertSame('a\\%b', WikiBoardListQuery::escapeLike('a%b'));
        $this->assertSame('a\\_b', WikiBoardListQuery::escapeLike('a_b'));
    }

    public function test_백슬래시가_먼저_처리된다(): void
    {
        // '\' 를 나중에 바꾸면 '%' 를 escape 하며 붙인 '\' 가 다시 escape 돼 '\\\%' 가 된다.
        $this->assertSame('\\\\', WikiBoardListQuery::escapeLike('\\'));
        $this->assertSame('\\\\\\%', WikiBoardListQuery::escapeLike('\\%'));
    }

    public function test_보통_글자는_그대로다(): void
    {
        $this->assertSame('설치 방법', WikiBoardListQuery::escapeLike('설치 방법'));
        $this->assertSame('apple pie', WikiBoardListQuery::escapeLike('apple pie'));
        $this->assertSame('', WikiBoardListQuery::escapeLike(''));
    }

    public function test_목록_상한은_이_클래스가_스스로_가진다(): void
    {
        // 전에는 목록 응답이 색인 자리표시의 상한(INDEX_LIMIT)을 빌려 썼다. 그러면 한쪽
        // 사정으로 값을 바꿀 때 다른 쪽이 조용히 따라 움직인다. 지금 값이 같은 것은 우연이다.
        $this->assertTrue(defined(WikiBoardListQuery::class.'::LIST_LIMIT'));
        $this->assertIsInt(WikiBoardListQuery::LIST_LIMIT);
        $this->assertGreaterThan(0, WikiBoardListQuery::LIST_LIMIT);

        $reflection = new \ReflectionClass(WikiBoardListQuery::class);
        $this->assertSame(
            WikiBoardListQuery::class,
            $reflection->getReflectionConstant('LIST_LIMIT')->getDeclaringClass()->getName()
        );
    }

    public function test_색인_상한은_따로_남아_있다(): void
    {
        // 자리표시 쪽 상한은 그대로다 — 이번 정리는 목록 응답이 그것을 빌려 쓰지 않게 한 것이다.
        $this->assertIsInt(WikiDocListQuery::INDEX_LIMIT);
    }

    public function test_무작위_개수_상한은_20이다(): void
    {
        $this->assertSame(20, WikiBoardListQuery::RANDOM_COUNT_MAX);
        $this->assertLessThanOrEqual(WikiBoardListQuery::LIST_LIMIT, WikiBoardListQuery::RANDOM_COUNT_MAX);
    }

    public function test_무작위_개수는_한_쪽_개수와_상한_중_작은_것이다(): void
    {
        // 1쪽 고정이라 한 쪽에 안 들어가는 문서는 볼 수 없다. 그런데도 뽑으면 총 건수가
        // 한 쪽 개수보다 커져 뜻 없는 2쪽 페이저가 생긴다(모바일 15건 / 20건 실측).
        // 한 쪽 20건(데스크톱) / 15건(휴대폰) / 50건(상한에 걸림) / 1건.
        $this->assertSame(20, WikiBoardListQuery::randomCount(20));
        $this->assertSame(15, WikiBoardListQuery::randomCount(15));
        $this->assertSame(20, WikiBoardListQuery::randomCount(50));
        $this->assertSame(1, WikiBoardListQuery::randomCount(1));
    }

    public function test_한_쪽_개수가_쓸모없으면_상한을_쓴다(): void
    {
        // 호출부가 이미 1 이상을 확인하지만, 이 계산만 따로 시험할 수 있게 여기서도 막는다.
        $this->assertSame(20, WikiBoardListQuery::randomCount(0));
        $this->assertSame(20, WikiBoardListQuery::randomCount(-5));
    }

    public function test_공지_예외는_대문_글_하나뿐이다(): void
    {
        // 대문이 지정돼 있으면 그 글만 공지 조건에서 빠진다. 다른 공지에는 예외가 없다
        // (쿼리는 이 값 하나로만 `orWhere id = ?` 를 붙인다).
        $this->assertSame(38, WikiBoardListQuery::noticeExemptId(38));
        $this->assertSame(1, WikiBoardListQuery::noticeExemptId(1));
    }

    public function test_대문이_없으면_공지_예외도_없다(): void
    {
        // null 이면 쿼리가 `orWhere` 를 붙이지 않는다 → 공지는 전부 빠진다.
        $this->assertNull(WikiBoardListQuery::noticeExemptId(null));
    }

    public function test_쓸_수_없는_대문_id는_예외로_보지_않는다(): void
    {
        // 0·음수로 예외를 걸면 `id = 0` 같은 조건이 붙어 뜻이 없다.
        $this->assertNull(WikiBoardListQuery::noticeExemptId(0));
        $this->assertNull(WikiBoardListQuery::noticeExemptId(-1));
    }
}
