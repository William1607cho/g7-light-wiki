<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Light\Wiki\Models\WikiDoc;

/**
 * 위키 문서 조회 — 색인 표(`light_wiki_docs`)와 코어 글 표를 함께 본다.
 *
 * ## 후보에서 빼는 것 (대문 자리표시·랜덤)
 *
 * 삭제된 글(`deleted_at`), 게시 상태가 아닌 글(`status <> 'published'` — 블라인드 포함),
 * 비밀글(`is_secret`), 답글(`parent_id`), 그리고 대문 글 자신.
 *
 * 문서 **링크 판정**(파란/빨간)은 이 필터를 쓰지 않는다 — 색인 표에 줄이 있으면 그 제목은
 * 이미 임자가 있는 것이고, 실제로 볼 수 있는지는 코어가 상세 화면에서 판정한다.
 */
final class WikiDocQuery
{
    /** 색인 자리표시가 한 번에 늘어놓는 최대 문서 수 */
    public const INDEX_LIMIT = 2000;

    /** 최근수정 자리표시의 기본·최대 개수 */
    public const RECENT_DEFAULT = 10;

    public const RECENT_MAX = 50;

    /**
     * 정규화 제목 → 문서. 표기 여러 개를 **조회 1회**로 판정한다.
     *
     * @param  list<string>  $normalized  정규화 제목 목록
     * @return array<string, array{post_id: int, title: string}>
     */
    public static function resolve(int $boardId, array $normalized): array
    {
        $normalized = array_values(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== ''
        )));

        if ($normalized === []) {
            return [];
        }

        return WikiDoc::query()
            ->where('board_id', $boardId)
            ->whereIn('title_norm', $normalized)
            ->get(['post_id', 'title', 'title_norm'])
            ->mapWithKeys(static fn (WikiDoc $doc): array => [
                (string) $doc->title_norm => ['post_id' => (int) $doc->post_id, 'title' => (string) $doc->title],
            ])
            ->all();
    }

    /**
     * 최근 수정 목록.
     *
     * @return list<array{post_id: int, title: string}>
     */
    public static function recent(int $boardId, ?int $frontPostId, int $limit): array
    {
        $limit = max(1, min($limit, self::RECENT_MAX));

        return self::candidates($boardId, $frontPostId)
            ->orderByDesc('d.edited_at')
            ->orderByDesc('d.post_id')
            ->limit($limit)
            ->get()
            ->map(static fn ($row): array => [
                'post_id' => (int) $row->post_id,
                'title' => (string) $row->title,
            ])
            ->all();
    }

    /**
     * 색인용 전체 문서 목록.
     *
     * @return list<array{id: int, title: string, title_norm: string}>
     */
    public static function forIndex(int $boardId, ?int $frontPostId): array
    {
        return self::candidates($boardId, $frontPostId)
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
     */
    public static function randomPostId(int $boardId, ?int $frontPostId): ?int
    {
        $row = self::candidates($boardId, $frontPostId)
            ->inRandomOrder()
            ->limit(1)
            ->first();

        return $row === null ? null : (int) $row->post_id;
    }

    /**
     * 랜덤 후보 전체의 글 ID 목록 — 검증에서 "302 대상이 후보 안인가" 를 보기 위한 것.
     *
     * @return list<int>
     */
    public static function candidateIds(int $boardId, ?int $frontPostId): array
    {
        return self::candidates($boardId, $frontPostId)
            ->orderBy('d.post_id')
            ->get()
            ->map(static fn ($row): int => (int) $row->post_id)
            ->all();
    }

    /**
     * 후보 조회의 공통 뼈대 — 색인 표에 코어 글 표를 붙이고 제외 조건을 건다.
     */
    private static function candidates(int $boardId, ?int $frontPostId): Builder
    {
        $docs = (new WikiDoc)->getTable();
        $posts = (new Post)->getTable();

        $query = DB::table($docs.' as d')
            ->join($posts.' as p', 'p.id', '=', 'd.post_id')
            ->where('d.board_id', $boardId)
            ->whereNull('p.deleted_at')
            ->where('p.status', 'published')
            ->where('p.is_secret', 0)
            ->whereNull('p.parent_id')
            ->select(['d.post_id', 'd.title', 'd.title_norm', 'd.edited_at']);

        if ($frontPostId !== null) {
            $query->where('d.post_id', '<>', $frontPostId);
        }

        return $query;
    }
}
