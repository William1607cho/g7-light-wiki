<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 세트 설치·해제가 설정 파일에 쓸 값을 조립하는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 쓰는 키는 셋뿐이다: `wiki_boards`·`managed_boards`·`setup_state`. 코어 설정 저장은
 * **최상위 키 단위로 병합**하므로 다른 키는 건드리지 않는다. `wiki_boards` 는 배열 하나가
 * 한 키라 한 줄을 더할 때도 배열 전체를 다시 쓰는데, 호출부가 **방금 읽은 값**을 넘기므로
 * 다른 게시판(수동 등록 위키 포함)의 줄은 읽은 그대로 되쓰인다.
 *
 * `setup_state` 모양: `{completed_at: string|null, last_author_id: int|null}`.
 * - `completed_at` — 처음 성공한 세트 설치 시각. 해제해도 지우지 않는다(안내 배너 판정용).
 * - `last_author_id` — 직전 설치의 작성자. 화면이 다음 설치의 기본값으로 보여 준다.
 */
final class SetupSettingsPatch
{
    /** 설정 키 */
    public const STATE_KEY = 'setup_state';

    /**
     * 세트 설치가 끝난 뒤의 세 키.
     *
     * @param  array<string, mixed>  $current  지금 설정 전체
     * @param  array<string, mixed>  $entry  `managed_boards` 에 더할 한 줄 (board_id·seed_post_ids 포함)
     * @param  string  $now  ISO 8601 시각
     * @return array<string, mixed>
     */
    public static function afterSetup(array $current, array $entry, string $now): array
    {
        $boardId = (int) $entry['board_id'];
        $frontPostId = WikiBoardSettings::toId($entry['seed_post_ids'][SeedDocuments::FRONT] ?? null);

        $wikiBoards = WikiBoardSettings::normalize($current[WikiBoardSettings::KEY] ?? []);
        $wikiBoards[] = ['board_id' => $boardId, 'front_post_id' => $frontPostId];

        $state = self::state($current);

        return [
            WikiBoardSettings::KEY => WikiBoardSettings::normalize($wikiBoards),
            ManagedBoardList::KEY => ManagedBoardList::add(
                ManagedBoardList::normalize($current[ManagedBoardList::KEY] ?? []),
                $entry,
            ),
            self::STATE_KEY => [
                'completed_at' => $state['completed_at'] ?? $now,
                'last_author_id' => WikiBoardSettings::toId($entry['author_id'] ?? null),
            ],
        ];
    }

    /**
     * 해제 뒤의 세 키 — 그 게시판 줄만 빠지고 나머지는 읽은 그대로다.
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public static function afterRelease(array $current, int $boardId): array
    {
        $wikiBoards = array_values(array_filter(
            WikiBoardSettings::normalize($current[WikiBoardSettings::KEY] ?? []),
            static fn (array $row): bool => $row['board_id'] !== $boardId,
        ));

        return [
            WikiBoardSettings::KEY => $wikiBoards,
            ManagedBoardList::KEY => ManagedBoardList::remove(
                ManagedBoardList::normalize($current[ManagedBoardList::KEY] ?? []),
                $boardId,
            ),
            self::STATE_KEY => self::state($current),
        ];
    }

    /**
     * 정리된 `setup_state`.
     *
     * @param  array<string, mixed>  $current
     * @return array{completed_at: ?string, last_author_id: ?int}
     */
    public static function state(array $current): array
    {
        $raw = is_array($current[self::STATE_KEY] ?? null) ? $current[self::STATE_KEY] : [];

        return [
            'completed_at' => is_string($raw['completed_at'] ?? null) && $raw['completed_at'] !== '' ? $raw['completed_at'] : null,
            'last_author_id' => WikiBoardSettings::toId($raw['last_author_id'] ?? null),
        ];
    }
}
