<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\BoardFacts;
use Plugins\G7\Light\Wiki\Support\Setup\ConvertEligibility;

/**
 * 빈 게시판 위키화 판정.
 *
 * 글 수는 `posts_count` 칸이 아니라 행 수다 — 휴지통·답글·공지가 모두 한 행이다.
 * `BoardFacts::of()` 가 그렇게 센다는 것은 스테이징 측정으로 보고, 여기서는 **센 값으로 어떻게
 * 판정하는가**를 고정한다.
 */
class ConvertEligibilityTest extends TestCase
{
    private function facts(string $type = 'basic', int $rows = 0, int $categories = 0, bool $wiki = false): BoardFacts
    {
        return new BoardFacts(12, $type, $rows, $categories, $wiki);
    }

    public function test_글_0_카테고리_0_기본형이면_통과한다(): void
    {
        $this->assertNull(ConvertEligibility::reason($this->facts()));
        $this->assertTrue(ConvertEligibility::allows($this->facts()));
    }

    public function test_휴지통_글만_있어도_거부한다(): void
    {
        // posts_count 칸은 0 이어도 행이 1 이면 글이 있는 게시판이다.
        $this->assertSame(ConvertEligibility::HAS_POSTS, ConvertEligibility::reason($this->facts(rows: 1)));
    }

    public function test_답글이나_공지도_행이라_거부한다(): void
    {
        $this->assertSame(ConvertEligibility::HAS_POSTS, ConvertEligibility::reason($this->facts(rows: 3)));
    }

    public function test_카테고리가_있으면_거부한다(): void
    {
        $this->assertSame(ConvertEligibility::HAS_CATEGORIES, ConvertEligibility::reason($this->facts(categories: 1)));
    }

    public function test_기본형이_아니면_거부한다(): void
    {
        foreach (['forum', 'gallery', 'webzine', 'card'] as $type) {
            $this->assertSame(ConvertEligibility::NOT_BASIC, ConvertEligibility::reason($this->facts(type: $type)));
        }
    }

    public function test_이미_위키면_거부한다(): void
    {
        $this->assertSame(ConvertEligibility::ALREADY_WIKI, ConvertEligibility::reason($this->facts(wiki: true)));
    }

    public function test_사유는_정해진_순서로_하나만(): void
    {
        $this->assertSame(
            ConvertEligibility::ALREADY_WIKI,
            ConvertEligibility::reason($this->facts('forum', 5, 2, true)),
        );
        $this->assertSame(ConvertEligibility::NOT_BASIC, ConvertEligibility::reason($this->facts('forum', 5, 2)));
        $this->assertSame(ConvertEligibility::HAS_POSTS, ConvertEligibility::reason($this->facts('basic', 5, 2)));
    }

    public function test_상태_코드(): void
    {
        $this->assertSame(409, ConvertEligibility::status(ConvertEligibility::ALREADY_WIKI));
        $this->assertSame(422, ConvertEligibility::status(ConvertEligibility::HAS_POSTS));
        $this->assertSame(422, ConvertEligibility::status(ConvertEligibility::HAS_CATEGORIES));
        $this->assertSame(422, ConvertEligibility::status(ConvertEligibility::NOT_BASIC));
    }
}
