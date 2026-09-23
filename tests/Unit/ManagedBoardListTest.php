<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\ManagedBoardList;

/**
 * 설정 `managed_boards` — 정리·추가·빼기·존재 게시판 거르기.
 */
class ManagedBoardListTest extends TestCase
{
    private function entry(int $boardId, string $origin = 'created'): array
    {
        return [
            'board_id' => $boardId,
            'origin' => $origin,
            'seed_post_ids' => ['index' => 101, 'syntax' => 102, 'front' => 103],
            'author_id' => 1,
            'set_up_by' => 5,
            'set_up_at' => '2026-09-23T03:00:00+00:00',
        ];
    }

    public function test_형태가_어긋난_값을_정리한다(): void
    {
        $this->assertSame([], ManagedBoardList::normalize('x'));
        $this->assertSame([], ManagedBoardList::normalize([['board_id' => 0], 'y', ['origin' => 'created']]));
    }

    public function test_문자열_id_와_모르는_origin_을_정리한다(): void
    {
        $rows = ManagedBoardList::normalize([['board_id' => '12', 'origin' => 'weird', 'seed_post_ids' => ['front' => '9']]]);

        $this->assertSame(12, $rows[0]['board_id']);
        $this->assertSame('created', $rows[0]['origin']);
        $this->assertSame(9, $rows[0]['seed_post_ids']['front']);
        $this->assertNull($rows[0]['seed_post_ids']['index']);
    }

    public function test_추가하면_게시판_id_순으로_남는다(): void
    {
        $rows = ManagedBoardList::add([$this->entry(20)], $this->entry(12, 'converted'));

        $this->assertSame([12, 20], ManagedBoardList::boardIds($rows));
        $this->assertSame('converted', $rows[0]['origin']);
    }

    public function test_같은_게시판은_뒤의_것이_이긴다(): void
    {
        $rows = ManagedBoardList::add([$this->entry(12)], $this->entry(12, 'converted'));

        $this->assertCount(1, $rows);
        $this->assertSame('converted', $rows[0]['origin']);
    }

    public function test_빼면_그_게시판만_빠진다(): void
    {
        $rows = ManagedBoardList::remove([$this->entry(12), $this->entry(20)], 12);

        $this->assertSame([20], ManagedBoardList::boardIds($rows));
    }

    public function test_없는_게시판을_빼도_그대로다(): void
    {
        $rows = ManagedBoardList::remove([$this->entry(12)], 99);

        $this->assertSame([12], ManagedBoardList::boardIds($rows));
    }

    public function test_실제로_있는_게시판만_남긴다(): void
    {
        $rows = [$this->entry(12), $this->entry(20), $this->entry(30)];

        $this->assertSame([12, 30], ManagedBoardList::existingIds($rows, [1, 12, 30]));
        $this->assertSame([], ManagedBoardList::existingIds($rows, []));
    }
}
