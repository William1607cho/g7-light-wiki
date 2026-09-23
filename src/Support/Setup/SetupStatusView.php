<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 설정 화면(`GET …/admin/wiki-setup`)의 응답을 조립하는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 화면이 판단해야 할 것을 **여기서 정해 값으로** 싣는다 — 화면은 받은 값을 보여 주기만 한다.
 * - `needs_setup` — 실제로 있는 관리 게시판이 하나도 없으면 참("설정을 마쳐 주세요" 안내)
 * - `default_author_id` — 직전 작성자가 지금도 후보면 그 사람, 아니면 첫 후보, 후보가 없으면 null
 * - 관리 게시판 줄의 `seed_count`(살아 있는 시드 글 수)·`author`(`{id, name}`)
 *
 * 6-1 에서 정한 칸(`setup_completed`·`last_author_id`·`author_candidates`·`convertible_boards`·
 * `managed_boards[].board_id·name·slug·exists·origin·front_post_id·set_up_at`)은 이름·모양 그대로다.
 */
final class SetupStatusView
{
    /**
     * @param  array<string, mixed>  $settings  지금 설정 전체
     * @param  list<array{id: int, name: string}>  $candidates  작성자 후보
     * @param  list<array{id: int, name: string, slug: string}>  $convertible  위키화할 수 있는 게시판
     * @param  array<int, array{name: string, slug: string}>  $labels  있는 게시판의 이름·slug (id 키)
     * @param  array<int, int>  $seedCounts  게시판 id => 살아 있는 시드 글 수
     * @param  array<int, string>  $userNames  사용자 id => 이름
     * @return array<string, mixed>
     */
    public static function build(
        array $settings,
        array $candidates,
        array $convertible,
        array $labels,
        array $seedCounts,
        array $userNames,
    ): array {
        $state = SetupSettingsPatch::state($settings);
        $rows = ManagedBoardList::normalize($settings[ManagedBoardList::KEY] ?? []);
        $fronts = self::frontPostIds($settings);

        $managed = array_map(static fn (array $row): array => [
            'board_id' => $row['board_id'],
            'name' => $labels[$row['board_id']]['name'] ?? null,
            'slug' => $labels[$row['board_id']]['slug'] ?? null,
            'exists' => isset($labels[$row['board_id']]),
            'origin' => $row['origin'],
            'front_post_id' => $fronts[$row['board_id']] ?? null,
            'set_up_at' => $row['set_up_at'],
            'seed_count' => $seedCounts[$row['board_id']] ?? 0,
            'author' => self::author($row['author_id'], $userNames),
        ], $rows);

        return [
            'setup_completed' => $state['completed_at'] !== null,
            'needs_setup' => ManagedBoardList::existingIds($rows, array_keys($labels)) === [],
            'last_author_id' => $state['last_author_id'],
            'default_author_id' => self::defaultAuthor($state['last_author_id'], $candidates),
            'author_candidates' => $candidates,
            'convertible_boards' => $convertible,
            'managed_boards' => $managed,
        ];
    }

    /**
     * 직전 작성자가 지금도 후보면 그 사람, 아니면 첫 후보, 없으면 null.
     *
     * @param  list<array{id: int, name: string}>  $candidates
     */
    public static function defaultAuthor(?int $lastAuthorId, array $candidates): ?int
    {
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $candidates);

        if ($lastAuthorId !== null && in_array($lastAuthorId, $ids, true)) {
            return $lastAuthorId;
        }

        return $ids[0] ?? null;
    }

    /**
     * @param  array<int, string>  $userNames
     * @return array{id: int, name: string}|null
     */
    private static function author(?int $authorId, array $userNames): ?array
    {
        if ($authorId === null || ! isset($userNames[$authorId])) {
            return null;
        }

        return ['id' => $authorId, 'name' => $userNames[$authorId]];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, ?int>
     */
    private static function frontPostIds(array $settings): array
    {
        $fronts = [];

        foreach (WikiBoardSettings::normalize($settings[WikiBoardSettings::KEY] ?? []) as $row) {
            $fronts[$row['board_id']] = $row['front_post_id'];
        }

        return $fronts;
    }
}
