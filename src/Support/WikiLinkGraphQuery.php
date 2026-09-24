<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Light\Wiki\Models\WikiRef;
use Plugins\G7\Light\Wiki\Support\Doc\SecretScope;

/**
 * 문서 사이 링크 조회 — `[[#외톨이]]`·`[[#필요한문서]]` 가 쓴다.
 *
 * ## 보는 사람 기준이다
 *
 * 기존 목록(역링크·분류 소속·연표·최근·랜덤·색인)은 비밀글을 전부 뺀다({@see WikiVisibility::apply()}).
 * 이 두 목록은 **보는 사람이 읽을 수 있는 문서**를 센다 — 비밀글도 그 사람이 읽을 수 있으면
 * 들어간다({@see WikiVisibility::applyForViewer()}, 판정은 코어 게이트 — {@see SecretScope}).
 * 삭제·비게시·답글은 누구에게나 뺀다.
 *
 * ## 링크 대상 판정은 여기서 하지 않는다
 *
 * 이 클래스는 표기 행과 목록만 뜬다. "이 이름이 어느 문서인가" 는 화면 링크 색과 같은
 * {@see \Plugins\G7\Light\Wiki\Support\Doc\LinkTargets} 가 정하고, 그 결과(연결된 문서 ID·빨간 이름)를
 * 받아 목록을 만든다. 정렬은 DB 에서 한다.
 */
final class WikiLinkGraphQuery
{
    /**
     * 보는 사람이 읽을 수 있는 문서의 본문 링크 전부 — 조회 1회.
     *
     * @return list<array{id: int, post_id: int, target: string, target_norm: string}>
     */
    public static function linkRefs(int $boardId, SecretScope $scope): array
    {
        return self::links($boardId, $scope)
            ->orderBy('r.id')
            ->select(['r.id', 'r.post_id', 'r.target', 'r.target_norm'])
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'post_id' => (int) $row->post_id,
                'target' => (string) $row->target,
                'target_norm' => (string) $row->target_norm,
            ])
            ->all();
    }

    /**
     * 외톨이 문서 — `title_norm` 순. 조회 1회(넘치면 +1).
     *
     * @param  list<int>  $exclude  뺄 글 ID (대문·지금 문서)
     * @param  list<int>  $linkedIds  다른 문서가 링크한 문서 ID
     * @return array{items: list<array{post_id: int, title: string}>, more: int}
     */
    public static function orphans(int $boardId, SecretScope $scope, array $exclude, array $linkedIds, int $limit): array
    {
        $query = WikiVisibility::applyForViewer(
            DB::table(WikiVisibility::docsTable().' as d')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'd.post_id')
                ->where('d.board_id', $boardId),
            $scope,
        )
            ->where('d.title_norm', 'not like', WikiBoardListQuery::escapeLike(TitleNormalizer::normalize(WikiCategory::PREFIX)).'%');

        $drop = self::ids(array_merge($exclude, $linkedIds));

        if ($drop !== []) {
            $query->whereNotIn('d.post_id', $drop);
        }

        $found = WikiRefQuery::limited(
            $query->orderBy('d.title_norm')->orderBy('d.post_id')->select(['d.post_id', 'd.title']),
            $limit,
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'post_id' => (int) $row['post_id'],
                'title' => (string) $row['title'],
            ], $found['items']),
            'more' => $found['more'],
        ];
    }

    /**
     * 필요한 문서 — 링크한 문서 수 내림차순, 같으면 `target_norm` 순. 조회 1회(넘치면 +1).
     *
     * 한 문서가 같은 이름을 여러 번 걸어도 1로 센다. `first_ref` 는 그 이름을 건 링크 중 가장
     * 작은 표기 ID 다 — 표시 글자를 그 행의 원문에서 꺼낸다.
     *
     * @param  list<string>  $redNames  빨간 링크가 되는 이름(정규화)
     * @return array{items: list<array{target_norm: string, count: int, first_ref: int}>, more: int}
     */
    public static function wanted(int $boardId, SecretScope $scope, array $redNames, int $limit): array
    {
        $redNames = array_values(array_unique(array_filter($redNames, static fn (string $name): bool => $name !== '')));

        if ($redNames === []) {
            return ['items' => [], 'more' => 0];
        }

        $found = WikiRefQuery::limited(
            self::links($boardId, $scope)
                ->whereIn('r.target_norm', $redNames)
                ->groupBy('r.target_norm')
                ->orderByDesc('g7lw_count')
                ->orderBy('r.target_norm')
                ->select([
                    'r.target_norm',
                    DB::raw('COUNT(DISTINCT r.post_id) as g7lw_count'),
                    DB::raw('MIN(r.id) as g7lw_first'),
                ]),
            $limit,
        );

        return [
            'items' => array_map(static fn (array $row): array => [
                'target_norm' => (string) $row['target_norm'],
                'count' => (int) $row['g7lw_count'],
                'first_ref' => (int) $row['g7lw_first'],
            ], $found['items']),
            'more' => $found['more'],
        ];
    }

    /**
     * 이 게시판 비밀 문서의 작성자 — 글 ID => 작성자 ID. 조회 1회.
     *
     * {@see SecretScope} 가 글마다 코어 게이트에 물을 때 쓴다. 작성자가 없는(비회원) 글은 null 이다.
     *
     * @return array<int, int|null>
     */
    public static function secretAuthors(int $boardId): array
    {
        $authors = [];

        $rows = DB::table(WikiVisibility::docsTable().' as d')
            ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'd.post_id')
            ->where('d.board_id', $boardId)
            ->where('p.is_secret', 1)
            ->orderBy('p.id')
            ->select(['p.id', 'p.user_id'])
            ->get();

        foreach ($rows as $row) {
            $authors[(int) $row->id] = $row->user_id === null ? null : (int) $row->user_id;
        }

        return $authors;
    }

    /**
     * 보는 사람이 읽을 수 있는 문서의 링크 표기 — 표기 표에 문서 색인과 코어 글 표를 붙인다.
     */
    private static function links(int $boardId, SecretScope $scope): Builder
    {
        return WikiVisibility::applyForViewer(
            DB::table(WikiVisibility::refsTable().' as r')
                ->join(WikiVisibility::docsTable().' as d', 'd.post_id', '=', 'r.post_id')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'r.post_id')
                ->where('r.board_id', $boardId)
                ->where('r.kind', WikiRef::KIND_LINK),
            $scope,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));
    }
}
