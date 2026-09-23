<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\UninstallGuard;

/**
 * 제거 거부 판정 — 관리 게시판 0 이면 허용, 1 이상이면 개수를 담은 문구로 거부.
 */
class UninstallGuardTest extends TestCase
{
    private function message(): callable
    {
        return static fn (int $count): string => "위키 게시판 {$count}개가 등록돼 있습니다.";
    }

    public function test_관리_게시판이_없으면_허용한다(): void
    {
        $this->assertNull(UninstallGuard::refusal(0, $this->message()));
    }

    public function test_하나라도_있으면_거부한다(): void
    {
        $this->assertSame('위키 게시판 1개가 등록돼 있습니다.', UninstallGuard::refusal(1, $this->message()));
    }

    public function test_문구에_개수가_들어간다(): void
    {
        $this->assertSame('위키 게시판 3개가 등록돼 있습니다.', UninstallGuard::refusal(3, $this->message()));
    }

    public function test_허용이면_문구를_만들지_않는다(): void
    {
        $called = 0;

        UninstallGuard::refusal(0, static function (int $count) use (&$called): string {
            $called++;

            return '';
        });

        $this->assertSame(0, $called);
    }
}
