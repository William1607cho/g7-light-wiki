<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 설정 키 `managed_boards` 의 값 — 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 세트 설치로 마련한 게시판(새로 만든 것·위키화한 것)의 기록이다. 수동으로 등록한 위키
 * 게시판(`wiki_boards` 에만 있는 것)은 여기 없다 — 그래서 해제 API·제거 거부 판정이
 * 그 게시판들을 건드리지 않는다.
 *
 * 한 줄 모양:
 * `{board_id, origin: created|converted, seed_post_ids: {index, syntax, front}, author_id, set_up_by, set_up_at}`
 *
 * 설정 파일은 코어 범용 설정 API 로도 쓸 수 있어 모양이 어긋난 값이 들어올 수 있다. 그래서
 * {@see normalize()} 가 읽을 때마다 정리한다({@see \Plugins\G7\Light\Wiki\Support\WikiBoardSettings} 와 같은 방식).
 */
final class ManagedBoardList
{
    /** 설정 키 */
    public const KEY = 'managed_boards';

    public const ORIGIN_CREATED = 'created';

    public const ORIGIN_CONVERTED = 'converted';

    /**
     * 임의 입력을 줄 목록으로 정리한다 (board_id 가 없는 줄은 버리고, 같은 게시판은 뒤의 것이 이긴다).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $item) {
            $boardId = is_array($item) ? WikiBoardSettings::toId($item['board_id'] ?? null) : null;

            if ($boardId === null) {
                continue;
            }

            $rows[$boardId] = self::row($boardId, $item);
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * 한 줄을 더한다 (같은 게시판이 있으면 바꾼다).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    public static function add(array $rows, array $entry): array
    {
        return self::normalize([...$rows, $entry]);
    }

    /**
     * 그 게시판의 줄을 뺀다.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function remove(array $rows, int $boardId): array
    {
        return array_values(array_filter(
            self::normalize($rows),
            static fn (array $row): bool => $row['board_id'] !== $boardId,
        ));
    }

    /**
     * 관리 게시판 id 목록.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public static function boardIds(array $rows): array
    {
        return array_map(static fn (array $row): int => $row['board_id'], self::normalize($rows));
    }

    /**
     * 실제로 있는 게시판만 남긴 id 목록.
     *
     * 게시판이 관리자 화면에서 삭제됐거나, 커밋이 실패해 설정에만 id 가 남은 경우를 거른다.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $existingBoardIds
     * @return list<int>
     */
    public static function existingIds(array $rows, array $existingBoardIds): array
    {
        return array_values(array_intersect(self::boardIds($rows), $existingBoardIds));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function row(int $boardId, array $item): array
    {
        $origin = ($item['origin'] ?? null) === self::ORIGIN_CONVERTED ? self::ORIGIN_CONVERTED : self::ORIGIN_CREATED;
        $seeds = is_array($item['seed_post_ids'] ?? null) ? $item['seed_post_ids'] : [];

        return [
            'board_id' => $boardId,
            'origin' => $origin,
            'seed_post_ids' => [
                SeedDocuments::INDEX => WikiBoardSettings::toId($seeds[SeedDocuments::INDEX] ?? null),
                SeedDocuments::CATEGORY_INDEX => WikiBoardSettings::toId($seeds[SeedDocuments::CATEGORY_INDEX] ?? null),
                SeedDocuments::ORPHAN => WikiBoardSettings::toId($seeds[SeedDocuments::ORPHAN] ?? null),
                SeedDocuments::WANTED => WikiBoardSettings::toId($seeds[SeedDocuments::WANTED] ?? null),
                SeedDocuments::SYNTAX => WikiBoardSettings::toId($seeds[SeedDocuments::SYNTAX] ?? null),
                SeedDocuments::FRONT => WikiBoardSettings::toId($seeds[SeedDocuments::FRONT] ?? null),
            ],
            'author_id' => WikiBoardSettings::toId($item['author_id'] ?? null),
            'set_up_by' => WikiBoardSettings::toId($item['set_up_by'] ?? null),
            'set_up_at' => is_string($item['set_up_at'] ?? null) ? $item['set_up_at'] : null,
        ];
    }
}
