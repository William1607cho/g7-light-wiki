<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Support\Facades\DB;
use Plugins\G7\Light\Wiki\Models\WikiRef;

/**
 * `[[#분류색인]]` 이 늘어놓을 분류 이름의 원천 — 조회 2회.
 *
 * 분류는 두 곳에서 나온다. ⓐ 문서 본문의 분류 표기(`[[분류:이름]]`), ⓑ 제목이 `분류:` 로 시작하는
 * 문서. 가시성은 **기존 규칙**({@see WikiVisibility::apply()} — 비밀글 전부 제외)이다. 보는 사람
 * 기준이 아니다: 분류 이름은 기존 분류 소속 목록과 같은 기준으로 보여야 한다.
 */
final class WikiCategoryIndexQuery
{
    /**
     * ⓐ 보이는 문서의 분류 표기 — 표기 ID 순. 조회 1회.
     *
     * @return list<array{target: string, target_norm: string}>
     */
    public static function usedNames(int $boardId): array
    {
        return WikiVisibility::apply(
            DB::table(WikiVisibility::refsTable().' as r')
                ->join(WikiVisibility::docsTable().' as d', 'd.post_id', '=', 'r.post_id')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'r.post_id')
                ->where('r.board_id', $boardId)
                ->where('r.kind', WikiRef::KIND_CATEGORY)
        )
            ->orderBy('r.id')
            ->select(['r.target', 'r.target_norm'])
            ->get()
            ->map(static fn ($row): array => [
                'target' => (string) $row->target,
                'target_norm' => (string) $row->target_norm,
            ])
            ->all();
    }

    /**
     * ⓑ 보이는 분류 문서 — 글 ID 순. 조회 1회.
     *
     * @return list<array{post_id: int, title: string, title_norm: string}>
     */
    public static function categoryDocs(int $boardId): array
    {
        return WikiVisibility::apply(
            DB::table(WikiVisibility::docsTable().' as d')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'd.post_id')
                ->where('d.board_id', $boardId)
        )
            ->where('d.title_norm', 'like', WikiBoardListQuery::escapeLike(TitleNormalizer::normalize(WikiCategory::PREFIX)).'%')
            ->orderBy('d.post_id')
            ->select(['d.post_id', 'd.title', 'd.title_norm'])
            ->get()
            ->map(static fn ($row): array => [
                'post_id' => (int) $row->post_id,
                'title' => (string) $row->title,
                'title_norm' => (string) $row->title_norm,
            ])
            ->all();
    }
}
