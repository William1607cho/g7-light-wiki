<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Services\PluginSettingsService;

/**
 * 위키 게시판 설정 — `wiki_boards`.
 *
 * 값은 `[{board_id, front_post_id}]` 객체 배열이다. 게시판마다 한 줄이고, `front_post_id`
 * 는 비워 둘 수 있다(대문 없이 링크 기능만 쓰는 게시판).
 *
 * - **읽을 때마다 정리한다.** 코어의 범용 설정 API 로도 이 키를 쓸 수 있고 그쪽 검증은
 *   "배열인가" 뿐이라, 형태가 어긋난 값이 들어와 있을 수 있다.
 * - 게시판이 위키인지 판정하는 유일한 근거다. 모든 리스너·미들웨어가 첫 줄에서 이것을 본다.
 * - 존재 여부 검증(게시판·대문 글)은 저장 시점에 전용 admin API 가 한다. 여기서는 형태만 본다.
 */
final class WikiBoardSettings
{
    /** 플러그인 식별자 */
    public const IDENTIFIER = 'g7-light-wiki';

    /** 설정 키 */
    public const KEY = 'wiki_boards';

    /** 저장할 수 있는 최대 게시판 수 */
    public const MAX_BOARDS = 100;

    /**
     * 정리된 설정 목록.
     *
     * @return list<array{board_id: int, front_post_id: ?int}>
     */
    public static function all(): array
    {
        $raw = function_exists('plugin_settings') ? plugin_settings(self::IDENTIFIER) : [];
        $raw = is_array($raw) ? ($raw[self::KEY] ?? []) : [];

        return self::normalize($raw);
    }

    /**
     * 임의 입력을 `[{board_id, front_post_id}]` 로 정리한다 (중복 게시판 제거, board_id 오름차순).
     *
     * @return list<array{board_id: int, front_post_id: ?int}>
     */
    public static function normalize(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $boardId = self::toId($item['board_id'] ?? null);

            if ($boardId === null) {
                continue;
            }

            // 뒤에 온 항목이 이긴다 — 화면에서 마지막에 고친 값이다.
            $rows[$boardId] = [
                'board_id' => $boardId,
                'front_post_id' => self::toId($item['front_post_id'] ?? null),
            ];
        }

        ksort($rows);

        return array_values(array_slice($rows, 0, self::MAX_BOARDS, true));
    }

    /**
     * 위키 게시판 ID 목록.
     *
     * @return list<int>
     */
    public static function boardIds(): array
    {
        return array_map(static fn (array $row): int => $row['board_id'], self::all());
    }

    /**
     * 이 게시판이 위키인가.
     */
    public static function isWikiBoard(?int $boardId): bool
    {
        return $boardId !== null && in_array($boardId, self::boardIds(), true);
    }

    /**
     * 이 게시판의 대문 글 ID (없으면 null).
     */
    public static function frontPostId(int $boardId): ?int
    {
        foreach (self::all() as $row) {
            if ($row['board_id'] === $boardId) {
                return $row['front_post_id'];
            }
        }

        return null;
    }

    /**
     * 설정을 저장한다. 호출자가 존재하는 게시판·대문 글로 걸러서 넘긴다.
     *
     * @param  list<array{board_id: int, front_post_id: ?int}>  $rows
     * @param  string|null  $failureReason  실패 사유 (출력)
     */
    public static function save(array $rows, ?string &$failureReason = null): bool
    {
        return app(PluginSettingsService::class)->save(
            self::IDENTIFIER,
            [self::KEY => self::normalize($rows)],
            $failureReason,
        );
    }

    /**
     * 양의 정수로 읽을 수 있으면 그 값, 아니면 null.
     */
    private static function toId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (is_string($value) && ctype_digit(trim($value))) {
            $id = (int) trim($value);

            return $id >= 1 ? $id : null;
        }

        return null;
    }
}
