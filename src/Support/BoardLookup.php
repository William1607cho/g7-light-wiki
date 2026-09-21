<?php

namespace Plugins\G7\Light\Wiki\Support;

use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시판 슬러그 ↔ ID 변환 (요청 안에서 한 번만 조회).
 *
 * 훅과 미들웨어는 슬러그만 받고 설정은 ID 로 저장돼 있어 둘 사이를 자주 오간다.
 * 한 요청 안에서 같은 게시판을 여러 번 물어도 질의는 한 번이다.
 */
final class BoardLookup
{
    /** @var array<string, int|null> */
    private static array $idBySlug = [];

    /** @var array<int, string|null> */
    private static array $slugById = [];

    /**
     * 슬러그로 게시판 ID 를 찾는다 (없으면 null).
     */
    public static function id(string $slug): ?int
    {
        if ($slug === '') {
            return null;
        }

        if (! array_key_exists($slug, self::$idBySlug)) {
            $id = Board::query()->where('slug', $slug)->value('id');
            self::$idBySlug[$slug] = $id === null ? null : (int) $id;

            if ($id !== null) {
                self::$slugById[(int) $id] = $slug;
            }
        }

        return self::$idBySlug[$slug];
    }

    /**
     * ID 로 게시판 슬러그를 찾는다 (없으면 null).
     */
    public static function slug(int $boardId): ?string
    {
        if ($boardId < 1) {
            return null;
        }

        if (! array_key_exists($boardId, self::$slugById)) {
            $slug = Board::query()->where('id', $boardId)->value('slug');
            self::$slugById[$boardId] = $slug === null ? null : (string) $slug;

            if ($slug !== null) {
                self::$idBySlug[(string) $slug] = $boardId;
            }
        }

        return self::$slugById[$boardId];
    }

    /**
     * 시험·명령에서 캐시를 비운다.
     */
    public static function flush(): void
    {
        self::$idBySlug = [];
        self::$slugById = [];
    }
}
