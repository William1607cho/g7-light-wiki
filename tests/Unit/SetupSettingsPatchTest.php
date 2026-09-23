<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\SetupSettingsPatch;

/**
 * 세트 설치·해제가 쓰는 설정 세 키.
 *
 * 수동으로 등록한 위키 게시판(스테이징 8·9 와 같은 줄)은 설치·해제 어느 쪽에서도 **읽은 값
 * 그대로** 되쓰여야 한다.
 */
class SetupSettingsPatchTest extends TestCase
{
    /** @return array<string, mixed> 지금 스테이징 설정과 같은 모양 */
    private function current(): array
    {
        return [
            'wiki_boards' => [
                ['board_id' => 8, 'front_post_id' => 38],
                ['board_id' => 9, 'front_post_id' => 39],
            ],
        ];
    }

    private function entry(int $boardId = 12): array
    {
        return [
            'board_id' => $boardId,
            'origin' => 'created',
            'seed_post_ids' => ['index' => 101, 'syntax' => 102, 'front' => 103],
            'author_id' => 1,
            'set_up_by' => 5,
            'set_up_at' => '2026-09-23T03:00:00+00:00',
        ];
    }

    public function test_설치는_세_키만_쓴다(): void
    {
        $patch = SetupSettingsPatch::afterSetup($this->current(), $this->entry(), '2026-09-23T03:00:00+00:00');

        $this->assertSame(['wiki_boards', 'managed_boards', 'setup_state'], array_keys($patch));
    }

    public function test_설치하면_새_줄이_붙고_대문은_front_시드다(): void
    {
        $patch = SetupSettingsPatch::afterSetup($this->current(), $this->entry(), '2026-09-23T03:00:00+00:00');

        $this->assertSame([
            ['board_id' => 8, 'front_post_id' => 38],
            ['board_id' => 9, 'front_post_id' => 39],
            ['board_id' => 12, 'front_post_id' => 103],
        ], $patch['wiki_boards']);
    }

    public function test_수동_위키_줄은_관리_대상이_되지_않는다(): void
    {
        $patch = SetupSettingsPatch::afterSetup($this->current(), $this->entry(), '2026-09-23T03:00:00+00:00');

        $this->assertSame([12], array_column($patch['managed_boards'], 'board_id'));
    }

    public function test_설치_상태_기록(): void
    {
        $first = SetupSettingsPatch::afterSetup($this->current(), $this->entry(), 'T1');

        $this->assertSame(['completed_at' => 'T1', 'last_author_id' => 1], $first['setup_state']);

        // 두 번째 설치: 완료 시각은 처음 값을 지키고 직전 작성자만 바뀐다.
        $second = SetupSettingsPatch::afterSetup(
            array_merge($this->current(), $first),
            array_merge($this->entry(20), ['author_id' => 7]),
            'T2',
        );

        $this->assertSame(['completed_at' => 'T1', 'last_author_id' => 7], $second['setup_state']);
        $this->assertSame([12, 20], array_column($second['managed_boards'], 'board_id'));
    }

    public function test_해제하면_그_게시판만_빠진다(): void
    {
        $installed = array_merge(
            $this->current(),
            SetupSettingsPatch::afterSetup($this->current(), $this->entry(), 'T1'),
        );

        $patch = SetupSettingsPatch::afterRelease($installed, 12);

        $this->assertSame([
            ['board_id' => 8, 'front_post_id' => 38],
            ['board_id' => 9, 'front_post_id' => 39],
        ], $patch['wiki_boards']);
        $this->assertSame([], $patch['managed_boards']);
        // 완료 기록은 해제해도 남는다.
        $this->assertSame('T1', $patch['setup_state']['completed_at']);
    }

    public function test_설치_전_상태(): void
    {
        $this->assertSame(['completed_at' => null, 'last_author_id' => null], SetupSettingsPatch::state([]));
        $this->assertSame(['completed_at' => null, 'last_author_id' => null], SetupSettingsPatch::state(['setup_state' => []]));
    }
}
