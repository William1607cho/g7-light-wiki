<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 자리표시가 늘어놓을 **문서 목록**의 원천 — 색인 표에 코어 글 표를 붙여 후보를 고른다.
 *
 * 후보 조회의 뼈대({@see candidates()})가 여기 하나 있고, 최근수정·최근작성·색인·랜덤이
 * 정렬과 개수만 달리해 그 뼈대를 쓴다. 자리표시가 쓰는 **개수 상수**도 여기 모여 있다 —
 * 상수와 그 상수를 쓰는 조회가 떨어져 있으면 한쪽만 고치기 쉽다.
 *
 * ## 후보에서 빼는 것
 *
 * 삭제된 글(`deleted_at`), 게시 상태가 아닌 글(`status <> 'published'` — 블라인드 포함),
 * 비밀글(`is_secret`), 답글(`parent_id`) — 이 네 가지는 {@see WikiVisibility} 한 곳에
 * 적혀 있고 {@see WikiRefQuery} 도 같은 것을 쓴다. 여기에 대문 글과 "자리표시가 실린
 * 그 문서 자신" 을 `$exclude` 로 더 뺀다.
 *
 * 문서 **링크 판정**(파란/빨간)은 이 필터를 쓰지 않는다 — 색인 표에 줄이 있으면 그 제목은
 * 이미 임자가 있는 것이고, 실제로 볼 수 있는지는 코어가 상세 화면에서 판정한다.
 */
final class WikiDocListQuery
{
    /** 색인 자리표시가 한 번에 늘어놓는 최대 문서 수 */
    public const INDEX_LIMIT = 2000;

    /** 최근수정 자리표시의 기본·최대 개수 */
    public const RECENT_DEFAULT = 10;

    public const RECENT_MAX = 50;

    /** 최근작성 자리표시의 기본 개수 (최대는 RECENT_MAX 와 같다) */
    public const CREATED_DEFAULT = 5;

    /** 랜덤 목록(`[[#랜덤|N]]`, N>=2)의 최대 개수 */
    public const RANDOM_MAX = 50;

    /** 둘러보기 각 단의 기본 개수 */
    public const TOUR_DEFAULT = 5;

    /**
     * 최근 수정 목록.
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID (대문 글·자리표시가 실린 그 문서 자신)
     * @return list<array{post_id: int, title: string}>
     */
    public static function recent(int $boardId, array $exclude, int $limit): array
    {
        $limit = max(1, min($limit, self::RECENT_MAX));

        return self::titleRows(
            self::candidates($boardId, $exclude)
                ->orderByDesc('d.edited_at')
                ->orderByDesc('d.post_id')
                ->limit($limit)
        );
    }

    /**
     * 최근 **작성** 목록 — 코어 글 표의 `created_at` 내림차순.
     *
     * 색인 표의 `edited_at` 은 마지막 **수정** 시각이라 작성 순서와 다르다. 작성일의 정본은
     * 코어 `board_posts.created_at` 이고, 후보 조회가 이미 그 표를 조인하고 있어 추가 조회는 없다.
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID
     * @return list<array{post_id: int, title: string}>
     */
    public static function recentCreated(int $boardId, array $exclude, int $limit): array
    {
        $limit = max(1, min($limit, self::RECENT_MAX));

        return self::titleRows(
            self::candidates($boardId, $exclude)
                ->orderByDesc('p.created_at')
                ->orderByDesc('d.post_id')
                ->limit($limit)
        );
    }

    /**
     * 색인용 전체 문서 목록.
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID
     * @return list<array{id: int, title: string, title_norm: string}>
     */
    public static function forIndex(int $boardId, array $exclude): array
    {
        return self::candidates($boardId, $exclude)
            ->orderBy('d.title_norm')
            ->limit(self::INDEX_LIMIT)
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->post_id,
                'title' => (string) $row->title,
                'title_norm' => (string) $row->title_norm,
            ])
            ->all();
    }

    /**
     * 랜덤 문서 1건의 글 ID (후보가 없으면 null).
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID
     */
    public static function randomPostId(int $boardId, array $exclude): ?int
    {
        $row = self::candidates($boardId, $exclude)
            ->inRandomOrder()
            ->limit(1)
            ->first();

        return $row === null ? null : (int) $row->post_id;
    }

    /**
     * 랜덤 문서 N 건 — 서로 다른 문서만 나온다(같은 행을 두 번 뽑지 않는다).
     *
     * 목록으로 보여 줄 것이라 제목이 필요하다. 그래서 ID 하나만 돌려주는
     * {@see randomPostId()} 와 따로 둔다.
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID
     * @return list<array{post_id: int, title: string}>
     */
    public static function randomDocs(int $boardId, array $exclude, int $limit): array
    {
        $limit = max(1, min($limit, self::RANDOM_MAX));

        return self::titleRows(
            self::candidates($boardId, $exclude)
                ->inRandomOrder()
                ->limit($limit)
        );
    }

    /**
     * 조회 결과를 `{post_id, title}` 목록으로 옮긴다.
     *
     * @return list<array{post_id: int, title: string}>
     */
    private static function titleRows(Builder $query): array
    {
        return $query->get()
            ->map(static fn ($row): array => [
                'post_id' => (int) $row->post_id,
                'title' => (string) $row->title,
            ])
            ->all();
    }

    /**
     * 후보 조회의 공통 뼈대 — 색인 표에 코어 글 표를 붙이고 제외 조건을 건다.
     *
     * 제외 대상이 **목록**인 이유: 1.5단계부터 자리표시가 대문 아닌 문서에도 실리므로,
     * 대문 글과 "자리표시가 실린 그 문서 자신" 을 함께 빼야 한다.
     *
     * @param  list<int>  $exclude  뺄 글 ID (0 이하와 중복은 알아서 걸러낸다)
     */
    private static function candidates(int $boardId, array $exclude): Builder
    {
        $query = WikiVisibility::apply(
            DB::table(WikiVisibility::docsTable().' as d')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'd.post_id')
                ->where('d.board_id', $boardId)
        )->select(['d.post_id', 'd.title', 'd.title_norm', 'd.edited_at', 'p.created_at']);

        $exclude = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $exclude),
            static fn (int $id): bool => $id > 0
        )));

        if ($exclude !== []) {
            $query->whereNotIn('d.post_id', $exclude);
        }

        return $query;
    }
}
