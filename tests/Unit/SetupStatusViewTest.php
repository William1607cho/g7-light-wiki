<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\SetupStatusView;

/**
 * 설정 화면 상태 응답 — 화면이 판단하지 않도록 서버가 정해 싣는 값.
 */
class SetupStatusViewTest extends TestCase
{
    /** @return array<string, mixed> */
    private function settings(array $managed = [], array $state = []): array
    {
        return [
            'wiki_boards' => [
                ['board_id' => 8, 'front_post_id' => 38],
                ['board_id' => 11, 'front_post_id' => 76],
            ],
            'managed_boards' => $managed,
            'setup_state' => $state,
        ];
    }

    private function row(int $boardId, int $authorId = 1, string $origin = 'created'): array
    {
        return [
            'board_id' => $boardId,
            'origin' => $origin,
            'seed_post_ids' => ['index' => 74, 'syntax' => 75, 'front' => 76],
            'author_id' => $authorId,
            'set_up_by' => 5,
            'set_up_at' => '2026-09-23T03:31:21+00:00',
        ];
    }

    private function candidates(): array
    {
        return [['id' => 1, 'name' => '관리자'], ['id' => 3, 'name' => '다른 관리자']];
    }

    public function test_관리_게시판이_없으면_설정이_필요하다(): void
    {
        $out = SetupStatusView::build($this->settings(), $this->candidates(), [], [], [], []);

        $this->assertTrue($out['needs_setup']);
        $this->assertSame([], $out['managed_boards']);
    }

    public function test_관리_게시판이_실제로_있으면_설정이_끝났다(): void
    {
        $out = SetupStatusView::build(
            $this->settings([$this->row(11)]), $this->candidates(), [],
            [11 => ['name' => '위키', 'slug' => 'wiki']], [11 => 3], [1 => '관리자'],
        );

        $this->assertFalse($out['needs_setup']);
    }

    public function test_기록만_있고_게시판이_지워졌으면_설정이_필요하다(): void
    {
        $out = SetupStatusView::build($this->settings([$this->row(11)]), $this->candidates(), [], [], [], []);

        $this->assertTrue($out['needs_setup']);
        $this->assertFalse($out['managed_boards'][0]['exists']);
        $this->assertNull($out['managed_boards'][0]['name']);
    }

    public function test_관리_게시판_줄에_시드_글_수와_작성자가_붙는다(): void
    {
        $out = SetupStatusView::build(
            $this->settings([$this->row(11)]), $this->candidates(), [],
            [11 => ['name' => '위키', 'slug' => 'wiki']], [11 => 2], [1 => '관리자'],
        );
        $row = $out['managed_boards'][0];

        $this->assertSame(11, $row['board_id']);
        $this->assertSame('위키', $row['name']);
        $this->assertSame('wiki', $row['slug']);
        $this->assertTrue($row['exists']);
        $this->assertSame('created', $row['origin']);
        $this->assertSame(76, $row['front_post_id']);
        $this->assertSame(2, $row['seed_count']);
        $this->assertSame(['id' => 1, 'name' => '관리자'], $row['author']);
    }

    public function test_작성자를_찾지_못하면_null(): void
    {
        $out = SetupStatusView::build(
            $this->settings([$this->row(11, 9)]), $this->candidates(), [],
            [11 => ['name' => '위키', 'slug' => 'wiki']], [11 => 3], [],
        );

        $this->assertNull($out['managed_boards'][0]['author']);
    }

    public function test_시드_글_수를_모르면_0(): void
    {
        $out = SetupStatusView::build(
            $this->settings([$this->row(11)]), $this->candidates(), [],
            [11 => ['name' => '위키', 'slug' => 'wiki']], [], [],
        );

        $this->assertSame(0, $out['managed_boards'][0]['seed_count']);
    }

    public function test_기본_작성자는_직전_작성자가_후보일_때_그_사람(): void
    {
        $this->assertSame(3, SetupStatusView::defaultAuthor(3, $this->candidates()));
    }

    public function test_직전_작성자가_후보가_아니면_첫_후보(): void
    {
        $this->assertSame(1, SetupStatusView::defaultAuthor(9, $this->candidates()));
        $this->assertSame(1, SetupStatusView::defaultAuthor(null, $this->candidates()));
    }

    public function test_후보가_없으면_null(): void
    {
        $this->assertNull(SetupStatusView::defaultAuthor(1, []));
    }

    public function test_6_1_의_칸은_그대로_있다(): void
    {
        $out = SetupStatusView::build(
            $this->settings([], ['completed_at' => 'T1', 'last_author_id' => 3]), $this->candidates(),
            [['id' => 12, 'name' => '빈 게시판', 'slug' => 'empty']], [], [], [],
        );

        $this->assertTrue($out['setup_completed']);
        $this->assertSame(3, $out['last_author_id']);
        $this->assertSame(3, $out['default_author_id']);
        $this->assertSame($this->candidates(), $out['author_candidates']);
        $this->assertSame([['id' => 12, 'name' => '빈 게시판', 'slug' => 'empty']], $out['convertible_boards']);
        $this->assertSame(
            ['setup_completed', 'needs_setup', 'last_author_id', 'default_author_id', 'author_candidates', 'convertible_boards', 'managed_boards'],
            array_keys($out),
        );
    }
}
