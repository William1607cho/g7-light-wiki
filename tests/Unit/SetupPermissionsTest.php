<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\SetupPermissions;

/**
 * 세트 설치 API 권한 — 플러그인 설정 권한과 게시판 생성 권한을 **둘 다** 요구한다.
 *
 * 판정은 코어 `permission` 미들웨어가 한다. 코어 규칙상 `a|b` 는 세 번째 인자가 없으면
 * AND 다. 여기서는 그 문자열이 두 권한을 모두 담고 OR 로 바뀌지 않았음을 고정한다.
 */
class SetupPermissionsTest extends TestCase
{
    public function test_설치에는_두_권한이_모두_필요하다(): void
    {
        $this->assertSame(['core.plugins.update', 'sirsoft-board.boards.create'], SetupPermissions::SETUP);
    }

    public function test_미들웨어는_두_권한을_AND_로_건다(): void
    {
        $middleware = SetupPermissions::setupMiddleware();

        $this->assertSame('permission:admin,core.plugins.update|sirsoft-board.boards.create', $middleware);
        // 세 번째 인자(`,false`)가 붙으면 OR 가 된다 — 붙지 않아야 한다.
        $this->assertSame(1, substr_count($middleware, ','));
    }

    public function test_하나만_있는_문자열이_아니다(): void
    {
        $this->assertNotSame('permission:admin,core.plugins.update', SetupPermissions::setupMiddleware());
        $this->assertNotSame('permission:admin,sirsoft-board.boards.create', SetupPermissions::setupMiddleware());
    }

    public function test_조회는_읽기_권한만_해제는_수정_권한만(): void
    {
        $this->assertSame('permission:admin,core.plugins.read', SetupPermissions::readMiddleware());
        $this->assertSame('permission:admin,core.plugins.update', SetupPermissions::releaseMiddleware());
    }
}
