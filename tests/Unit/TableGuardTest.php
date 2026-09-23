<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Setup\TableGuard;

/**
 * 표가 없을 때의 안전 처리 — `--delete-data` 는 표를 먼저 지우고 `uninstall()` 을 부른다.
 */
class TableGuardTest extends TestCase
{
    public function test_표가_없으면_작업을_부르지_않고_대체값을_돌려준다(): void
    {
        $calls = 0;
        $guard = new TableGuard(static fn (string $table): bool => false);

        $result = $guard->run(static function () use (&$calls): string {
            $calls++;

            throw new \RuntimeException('표 없는 조회');
        }, 'fallback');

        $this->assertSame(0, $calls);
        $this->assertSame('fallback', $result);
    }

    public function test_하나라도_없으면_부르지_않는다(): void
    {
        $guard = new TableGuard(static fn (string $table): bool => $table === 'light_wiki_docs');

        $this->assertSame([0, 0], $guard->run(static fn (): array => [5, 5], [0, 0]));
    }

    public function test_표가_있으면_한_번_부른다(): void
    {
        $calls = 0;
        $guard = new TableGuard(static fn (string $table): bool => true);

        $result = $guard->run(static function () use (&$calls): int {
            $calls++;

            return 7;
        });

        $this->assertSame(1, $calls);
        $this->assertSame(7, $result);
    }

    public function test_보는_표는_이_플러그인의_둘이다(): void
    {
        $this->assertSame(['light_wiki_docs', 'light_wiki_refs'], TableGuard::TABLES);
    }
}
