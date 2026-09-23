<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 플러그인 표가 있을 때만 실행한다.
 *
 * `plugin:uninstall --delete-data` 는 표를 **먼저** DROP 하고 그 뒤에 `uninstall()` 을
 * 부른다. 표를 만지는 코드가 그 순서에 걸려도 예외가 나지 않게, 표 존재를 먼저 본다.
 * 존재 판정은 주입받는다 — 운영에서는 `Schema::hasTable`, 단위 시험에서는 가짜 함수.
 */
final class TableGuard
{
    /** 이 플러그인이 쓰는 표 */
    public const TABLES = ['light_wiki_docs', 'light_wiki_refs'];

    /**
     * @param  callable(string): bool  $hasTable
     */
    public function __construct(private $hasTable) {}

    /**
     * 운영용 — 코어 스키마 빌더로 판정한다.
     */
    public static function schema(): self
    {
        return new self(static fn (string $table): bool => \Illuminate\Support\Facades\Schema::hasTable($table));
    }

    /**
     * 표가 모두 있으면 `$work` 를 실행하고 그 결과를, 하나라도 없으면 `$fallback` 을 돌려준다.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @param  T  $fallback
     * @return T
     */
    public function run(callable $work, mixed $fallback = null): mixed
    {
        foreach (self::TABLES as $table) {
            if (! ($this->hasTable)($table)) {
                return $fallback;
            }
        }

        return $work();
    }
}
