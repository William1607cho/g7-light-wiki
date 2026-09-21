<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;

/**
 * 목록 검색이 LIKE 와일드카드를 글자로 되돌리는지 본다.
 *
 * DB 를 타지 않는 순수 함수만 시험한다 — 실제 조회는 스테이징 검증(L2·L3)에서 잰다.
 */
class WikiDocQueryEscapeTest extends TestCase
{
    public function test_와일드카드는_글자로_바뀐다(): void
    {
        $this->assertSame('\\%', WikiDocQuery::escapeLike('%'));
        $this->assertSame('\\_', WikiDocQuery::escapeLike('_'));
        $this->assertSame('a\\%b', WikiDocQuery::escapeLike('a%b'));
        $this->assertSame('a\\_b', WikiDocQuery::escapeLike('a_b'));
    }

    public function test_백슬래시가_먼저_처리된다(): void
    {
        // '\' 를 나중에 바꾸면 '%' 를 escape 하며 붙인 '\' 가 다시 escape 돼 '\\\%' 가 된다.
        $this->assertSame('\\\\', WikiDocQuery::escapeLike('\\'));
        $this->assertSame('\\\\\\%', WikiDocQuery::escapeLike('\\%'));
    }

    public function test_보통_글자는_그대로다(): void
    {
        $this->assertSame('설치 방법', WikiDocQuery::escapeLike('설치 방법'));
        $this->assertSame('apple pie', WikiDocQuery::escapeLike('apple pie'));
        $this->assertSame('', WikiDocQuery::escapeLike(''));
    }
}
