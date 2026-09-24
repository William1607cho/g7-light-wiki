<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Plugins\G7\Light\Wiki\Models\WikiRef;

/**
 * 표기 색인 조회 — 역링크·분류 소속·별칭 해석·연표.
 *
 * ## 무엇을 빼는가
 *
 * 남에게 보이는 목록(역링크·분류 소속·연표·별칭 해석)은 모두 {@see visible()} 한 뼈대를
 * 쓴다: 삭제된 글, 게시 상태가 아닌 글(블라인드 포함), **비밀글**, 답글을 뺀다.
 * 비밀글에서 뽑은 표기가 남의 목록에 실리면 제목만으로도 그 글의 존재와 내용이 드러난다.
 *
 * 문서 자신의 화면에 붙는 자기 분류 줄·별칭 줄은 이 조회를 쓰지 않는다 — 그 글을 이미 열어
 * 본 사람에게 자기 것을 보이는 일이라 가릴 것이 없다.
 *
 * ## 조회 횟수
 *
 * 자동으로 붙는 영역이 몇 개든 **종류마다 1회**다. 분류가 다섯이어도 소속 목록 조회는 한 번
 * (`whereIn`)이고, 결과를 분류별로 나누는 일은 PHP 가 한다. 목록 상한은 `LIMIT n+1` 로 재어
 * "외 N건" 을 판정한다 — 전체를 세는 `COUNT(*)` 를 한 번 더 돌지 않는다.
 */
final class WikiRefQuery
{
    /** 역링크·분류 소속 목록의 최대 건수 */
    public const LIST_LIMIT = 100;

    /** 연표 목록의 최대 건수 */
    public const EVENT_LIMIT = 200;

    /**
     * 별칭 → 그 별칭을 가진 문서. 조회 1회.
     *
     * 같은 별칭을 여러 문서가 걸면 **`post_id` 가 작은 문서가 이긴다**(먼저 만들어진 쪽).
     * 실제 제목과의 우선순위는 여기서 정하지 않는다 — 호출부가 문서 제목 조회 결과를 먼저
     * 보고, 거기 없는 이름만 이 결과로 채운다.
     *
     * @param  list<string>  $normalized  정규화된 이름 목록
     * @return array<string, array{post_id: int, title: string}>  정규화 이름 => 문서
     */
    public static function aliasOwners(int $boardId, array $normalized): array
    {
        $normalized = self::cleanNames($normalized);

        if ($normalized === []) {
            return [];
        }

        $rows = self::visible($boardId, WikiRef::KIND_ALIAS)
            ->whereIn('r.target_norm', $normalized)
            // 작은 post_id 가 이긴다 — 뒤에 오는 행이 앞을 덮지 않도록 내림차순으로 받아
            // 덮어쓰기의 마지막이 가장 작은 id 가 되게 한다.
            ->orderByDesc('r.post_id')
            ->select(['r.target_norm', 'd.post_id', 'd.title'])
            ->get();

        $found = [];

        foreach ($rows as $row) {
            $found[(string) $row->target_norm] = [
                'post_id' => (int) $row->post_id,
                'title' => (string) $row->title,
            ];
        }

        return $found;
    }

    /**
     * 이 이름들을 가리키는 문서 목록 (역링크). 조회 1회.
     *
     * 이름에는 이 문서의 제목과 별칭이 모두 들어온다 — 별칭으로 건 링크도 본 문서의 역링크다.
     *
     * @param  list<string>  $normalized  이 문서의 제목·별칭(정규화)
     * @param  int  $excludePostId  자기 자신
     * @return array{items: list<array{post_id: int, title: string}>, more: int}
     */
    public static function backlinks(int $boardId, array $normalized, int $excludePostId, int $limit = self::LIST_LIMIT): array
    {
        $normalized = self::cleanNames($normalized);

        if ($normalized === []) {
            return ['items' => [], 'more' => 0];
        }

        $query = self::visible($boardId, WikiRef::KIND_LINK)
            ->whereIn('r.target_norm', $normalized)
            ->where('d.post_id', '<>', max(0, $excludePostId))
            ->groupBy('d.post_id', 'd.title', 'd.title_norm')
            ->orderBy('d.title_norm')
            ->select(['d.post_id', 'd.title', 'd.title_norm']);

        return self::limited($query, $limit);
    }

    /**
     * 분류별 소속 문서. 분류가 몇 개든 조회 1회.
     *
     * @param  list<string>  $normalized  정규화된 분류 이름 목록
     * @return array<string, array{items: list<array{post_id: int, title: string}>, more: int}>
     */
    public static function categoryMembers(int $boardId, array $normalized, int $limit = self::LIST_LIMIT): array
    {
        $normalized = self::cleanNames($normalized);

        if ($normalized === []) {
            return [];
        }

        // 분류가 몇이든 조회 1회다. 넉넉히 `분류 수 × (상한+1)` 까지 받아 PHP 에서 나눈다.
        // `+1` 이 있어야 어느 분류가 상한을 넘겼는지 알 수 있다.
        $base = self::visible($boardId, WikiRef::KIND_CATEGORY)
            ->whereIn('r.target_norm', $normalized)
            ->groupBy('r.target_norm', 'd.post_id', 'd.title', 'd.title_norm');

        $rows = (clone $base)
            ->orderBy('r.target_norm')
            ->orderBy('d.title_norm')
            ->limit(count($normalized) * ($limit + 1))
            ->select(['r.target_norm', 'd.post_id', 'd.title', 'd.title_norm'])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->target_norm][] = [
                'post_id' => (int) $row->post_id,
                'title' => (string) $row->title,
            ];
        }

        // 상한을 넘긴 분류가 있을 때만 정확한 건수를 한 번 더 센다.
        $overflow = [];

        foreach ($normalized as $name) {
            if (count($grouped[$name] ?? []) > $limit) {
                $overflow[] = $name;
            }
        }

        $totals = $overflow === [] ? [] : self::categoryTotals($boardId, $overflow);

        $out = [];

        foreach ($normalized as $name) {
            $items = $grouped[$name] ?? [];
            $out[$name] = [
                'items' => array_slice($items, 0, $limit),
                'more' => max(0, ($totals[$name] ?? count($items)) - $limit),
            ];
        }

        return $out;
    }

    /**
     * 분류별 소속 문서 수 — 상한을 넘긴 분류에만 쓴다. 조회 1회.
     *
     * @param  list<string>  $normalized
     * @return array<string, int>
     */
    private static function categoryTotals(int $boardId, array $normalized): array
    {
        $totals = [];

        $query = self::visible($boardId, WikiRef::KIND_CATEGORY);

        // 날것 SQL 안의 칸 이름은 문법기로 감싼다 — 표 접두어가 별칭에도 붙어(`… as g7_d`)
        // `d.post_id` 를 그대로 적으면 없는 별칭이 된다.
        $rows = $query
            ->whereIn('r.target_norm', $normalized)
            ->groupBy('r.target_norm')
            ->select(['r.target_norm', DB::raw('COUNT(DISTINCT '.$query->getGrammar()->wrap('d.post_id').') as g7lw_count')])
            ->get();

        foreach ($rows as $row) {
            $totals[(string) $row->target_norm] = (int) $row->g7lw_count;
        }

        return $totals;
    }

    /**
     * 게시판 전체의 사건 — 키 순. 조회 1회.
     *
     * 같은 키는 `post_id`, `seq` 순이다(명령서 T절).
     *
     * @return array{items: list<array{post_id: int, title: string, target: string, label: string}>, more: int}
     */
    public static function events(int $boardId, int $limit = self::EVENT_LIMIT): array
    {
        return self::limited(self::eventQuery($boardId), $limit);
    }

    /**
     * 주어진 글들의 사건 — 키 순. 조회 1회.
     *
     * `[[#연표|문서명]]` 이 쓴다. 대상은 "그 문서" 와 "그 문서를 링크한 문서들" 이다.
     *
     * @param  list<int>  $postIds
     * @return array{items: list<array{post_id: int, title: string, target: string, label: string}>, more: int}
     */
    public static function eventsOf(int $boardId, array $postIds, int $limit = self::EVENT_LIMIT): array
    {
        $postIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $postIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($postIds === []) {
            return ['items' => [], 'more' => 0];
        }

        return self::limited(self::eventQuery($boardId)->whereIn('r.post_id', $postIds), $limit);
    }

    /**
     * `[[#연표]]` · `[[#연표|문서명]]` 이 쓸 사건 목록.
     *
     * 인자가 없으면 게시판 전체다(조회 1회). 인자가 있으면 **그 문서와 그 문서를 링크한
     * 문서들**의 사건이다 — 대상 문서를 찾는 조회가 더 붙는다(문서명은 실제 제목일 수도
     * 별칭일 수도 있다).
     *
     * 전에는 이 절차가 미들웨어에 있었다. 조회가 조회 계층 밖에 있으면 "문서 한 번 그리는 데
     * 질의가 몇 번인가" 를 한자리에서 볼 수 없다.
     *
     * @return array{items: list<array{post_id: int, title: string, target: string, label: string}>, more: int}
     */
    public static function timelineFor(int $boardId, ?string $docName): array
    {
        if ($docName === null || trim($docName) === '') {
            return self::events($boardId);
        }

        $normalized = TitleNormalizer::normalize($docName);

        $doc = WikiDocLookup::resolve($boardId, [$normalized])[$normalized]
            ?? self::aliasOwners($boardId, [$normalized])[$normalized]
            ?? null;

        if ($doc === null) {
            return ['items' => [], 'more' => 0];
        }

        // 그 문서 + 그 문서를 가리킨 문서들. 중복은 `eventsOf` 가 걸러 낸다.
        $linkers = self::backlinks($boardId, [$normalized], 0, self::EVENT_LIMIT);

        $ids = array_merge(
            [(int) $doc['post_id']],
            array_map(static fn (array $row): int => (int) $row['post_id'], $linkers['items']),
        );

        return self::eventsOf($boardId, $ids);
    }

    /**
     * 이 이름들을 **가리킨** 문서의 글 ID — 봇 캐시 무효화가 쓴다. 조회 1회.
     *
     * 별칭이 바뀌면 그 별칭으로 링크를 건 문서들의 링크 색(있는 문서/없는 문서)이 달라진다.
     * 그 문서들의 봇 캐시를 비워야 화면이 바로 맞는다.
     *
     * 보임 여부로 거르지 **않는다** — 캐시를 비우는 일은 노출과 무관하고, 비밀글에 캐시가
     * 없으면 무효화가 그냥 헛돌 뿐이다.
     *
     * @param  list<string>  $normalized
     * @return list<int>
     */
    public static function linkerPostIds(int $boardId, array $normalized, int $limit): array
    {
        $normalized = self::cleanNames($normalized);

        if ($normalized === []) {
            return [];
        }

        return WikiRef::query()
            ->where('board_id', $boardId)
            ->where('kind', WikiRef::KIND_LINK)
            ->whereIn('target_norm', $normalized)
            ->distinct()
            ->limit(max(1, $limit))
            ->pluck('post_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 이 이름들을 **별칭으로 가진** 문서의 글 ID — 봇 캐시 무효화가 쓴다. 조회 1회.
     *
     * 링크 대상 이름이 실제 제목이 아니라 별칭일 수 있다. 그 문서의 역링크 목록이 바뀌므로
     * 함께 비운다.
     *
     * @param  list<string>  $normalized
     * @return list<int>
     */
    public static function aliasPostIds(int $boardId, array $normalized, int $limit): array
    {
        $normalized = self::cleanNames($normalized);

        if ($normalized === []) {
            return [];
        }

        return WikiRef::query()
            ->where('board_id', $boardId)
            ->where('kind', WikiRef::KIND_ALIAS)
            ->whereIn('target_norm', $normalized)
            ->distinct()
            ->limit(max(1, $limit))
            ->pluck('post_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 사건 조회의 공통 뼈대.
     */
    private static function eventQuery(int $boardId): Builder
    {
        return self::visible($boardId, WikiRef::KIND_EVENT)
            ->whereNotNull('r.sort_key')
            ->orderBy('r.sort_key')
            ->orderBy('r.post_id')
            ->orderBy('r.seq')
            ->select(['d.post_id', 'd.title', 'r.target', 'r.label']);
    }

    /**
     * 남에게 보여도 되는 표기만 남기는 뼈대 — 표기 표에 문서 색인과 코어 글 표를 붙인다.
     *
     * 빼는 것은 {@see WikiDocListQuery} 의 후보 조건과 같다: 삭제·비게시·비밀글·답글.
     */
    private static function visible(int $boardId, string $kind): Builder
    {
        return WikiVisibility::apply(
            DB::table(WikiVisibility::refsTable().' as r')
                ->join(WikiVisibility::docsTable().' as d', 'd.post_id', '=', 'r.post_id')
                ->join(WikiVisibility::postsTable().' as p', 'p.id', '=', 'r.post_id')
                ->where('r.board_id', $boardId)
                ->where('r.kind', $kind)
        );
    }

    /**
     * 상한을 재며 목록을 받는다 — `LIMIT n+1` 로 "더 있는가" 를 먼저 본다.
     *
     * **넘쳤을 때만** 전체를 한 번 더 세어 "외 N건" 의 N 을 정확히 만든다. 상한을 넘는 문서는
     * 드물므로 보통은 조회가 1회로 끝나고, 넘친 문서에서만 1회가 붙는다.
     *
     * 세기는 서브쿼리로 감싼다 — 이 조회들에는 `GROUP BY` 가 붙어 있어 `count()` 를 그냥
     * 부르면 그룹마다 한 줄씩 돌아온다.
     *
     * {@see WikiLinkGraphQuery} 도 같은 방식으로 재므로 공개한다.
     *
     * @return array{items: list<array<string, mixed>>, more: int}
     */
    public static function limited(Builder $query, int $limit): array
    {
        $limit = max(1, $limit);

        $rows = (clone $query)->limit($limit + 1)->get();

        $items = $rows->take($limit)
            ->map(static fn ($row): array => array_map(
                static fn ($value): mixed => $value ?? '',
                (array) $row,
            ))
            ->values()
            ->all();

        if ($rows->count() <= $limit) {
            return ['items' => $items, 'more' => 0];
        }

        $total = (int) DB::table(DB::raw('('.$query->toSql().') as g7lw_total'))
            ->mergeBindings($query)
            ->count();

        return ['items' => $items, 'more' => max(0, $total - $limit)];
    }

    /**
     * 이름 목록을 다듬는다 — 빈 값 제거·중복 제거.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function cleanNames(array $names): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($name): string => (string) $name, $names),
            static fn (string $name): bool => $name !== ''
        )));
    }
}
