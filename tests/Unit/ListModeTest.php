<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Doc\ListMode;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * `sort_by` 한 칸에서 목록 모드를 읽는 규칙.
 *
 * 주소를 만드는 쪽({@see WikiUrl::boardList()})과 읽는 쪽이 같은 상수를 쓰는지도 함께 잡는다 —
 * 한쪽만 고치면 링크가 오류 없이 조용히 대문 1건으로 떨어진다.
 */
class ListModeTest extends TestCase
{
    public function test_허용된_두_값만_모드가_된다(): void
    {
        $this->assertSame(ListMode::RECENT, ListMode::fromSortBy('g7lw-recent'));
        $this->assertSame(ListMode::RANDOM, ListMode::fromSortBy('g7lw-random'));
    }

    public function test_주소를_만드는_상수와_읽는_값이_같다(): void
    {
        $this->assertSame(ListMode::RECENT, ListMode::fromSortBy(WikiUrl::SORT_RECENT));
        $this->assertSame(ListMode::RANDOM, ListMode::fromSortBy(WikiUrl::SORT_RANDOM));
    }

    public function test_허용_밖_값은_모드가_없다(): void
    {
        foreach (['', 'id', 'created_at', 'g7lw-', 'g7lw-recent2', 'G7LW-RECENT', '../etc'] as $value) {
            $this->assertSame(ListMode::NONE, ListMode::fromSortBy($value), "값: {$value}");
        }
    }

    public function test_문자열이_아니면_모드가_없다(): void
    {
        foreach ([null, 1, 1.5, true, [], ['g7lw-recent']] as $value) {
            $this->assertSame(ListMode::NONE, ListMode::fromSortBy($value));
        }
    }

    public function test_앞뒤_공백은_털어_낸다(): void
    {
        $this->assertSame(ListMode::RECENT, ListMode::fromSortBy(' g7lw-recent '));
        $this->assertSame(ListMode::RANDOM, ListMode::fromSortBy("\tg7lw-random\n"));
    }

    public function test_모드_값_셋은_서로_다르다(): void
    {
        $modes = [ListMode::NONE, ListMode::RECENT, ListMode::RANDOM];

        $this->assertCount(3, array_unique($modes));
    }
}
