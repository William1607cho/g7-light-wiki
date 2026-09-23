<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\NewBoardData;

/**
 * 새 위키 게시판 생성 데이터 — 모듈 기본값 위에 위키 값을 덮는다.
 */
class NewBoardDataTest extends TestCase
{
    /** @return array<string, mixed> 스테이징 `basic_defaults` 와 같은 모양의 표본 */
    private function defaults(): array
    {
        return [
            'type' => 'gallery',
            'per_page' => 20,
            'show_view_count' => true,
            'use_reply' => true,
            'use_comment' => true,
            'notify_admin_on_post' => true,
            'max_content_length' => 100000,
            'min_content_length' => 2,
            'default_board_permissions' => ['posts.read' => ['guest']],
            'blocked_keywords' => ['x', 'y'],
            'secret_mode' => 'disabled',
            'nullable_key' => null,
        ];
    }

    public function test_기본값의_스칼라_값이_깔린다(): void
    {
        $data = NewBoardData::build($this->defaults(), '위키', 'wiki', 'uuid-1');

        $this->assertSame(100000, $data['max_content_length']);
        $this->assertSame(2, $data['min_content_length']);
        $this->assertSame(20, $data['per_page']);
        $this->assertSame('disabled', $data['secret_mode']);
    }

    public function test_배열_값과_null_은_깔지_않는다(): void
    {
        $data = NewBoardData::build($this->defaults(), '위키', 'wiki', 'uuid-1');

        $this->assertArrayNotHasKey('default_board_permissions', $data);
        $this->assertArrayNotHasKey('blocked_keywords', $data);
        $this->assertArrayNotHasKey('nullable_key', $data);
    }

    public function test_위키_값이_기본값을_이긴다(): void
    {
        $data = NewBoardData::build($this->defaults(), '위키', 'wiki', 'uuid-1');

        $this->assertFalse($data['show_view_count']);
        $this->assertFalse($data['use_reply']);
        $this->assertFalse($data['use_comment']);
        $this->assertFalse($data['notify_admin_on_post']);
        $this->assertSame('basic', $data['type']);
    }

    public function test_이름_slug_관리자_권한(): void
    {
        $data = NewBoardData::build($this->defaults(), '위키', 'my-wiki', 'uuid-1');

        $this->assertSame('위키', $data['name']);
        $this->assertSame('my-wiki', $data['slug']);
        $this->assertSame(['uuid-1'], $data['board_manager_ids']);
        // 비워 보내야 모듈이 기본 권한(공개)을 전개한다.
        $this->assertSame([], $data['permissions']);
        $this->assertSame([], $data['categories']);
    }

    public function test_기본값이_비어도_위키_값은_모두_있다(): void
    {
        $data = NewBoardData::build([], '위키', 'wiki', 'uuid-1');

        foreach (NewBoardData::WIKI_SETTINGS as $key => $value) {
            $this->assertSame($value, $data[$key]);
        }
    }
}
