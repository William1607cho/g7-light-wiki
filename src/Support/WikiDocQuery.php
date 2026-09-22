<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Helpers\PermissionHelper;
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

    /** 최근작성 자리표시의 기본 개수 (최대는 RECENT_MAX 와 같다) */
    public const CREATED_DEFAULT = 5;

    /** 랜덤 목록(`[[#랜덤|N]]`, N>=2)의 최대 개수 */
    public const RANDOM_MAX = 50;

    /** 둘러보기 각 단의 기본 개수 */
    public const TOUR_DEFAULT = 5;

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
     * 제목 검색 — 정규화 제목에 정규화 검색어가 **들어 있는** 문서의 글 ID.
     *
     * 정렬은 `title_norm` 오름차순이다. `title_norm` 은 `utf8mb4_bin` 이라 바이트 비교이고,
     * {@see TitleNormalizer} 가 이미 라틴 소문자화·전각 접기를 끝냈으므로 검색어도 같은
     * 정규화를 거쳐 들어와야 한다.
     *
     * LIKE 와일드카드(`%`·`_`)와 이스케이프 문자(`\`)는 글자 그대로 찾도록 막는다 —
     * 코어 `PostRepository::escapeLikeKeyword()` 와 같은 방식이다.
     *
     * @param  string  $normalizedKeyword  정규화된 검색어 (빈 문자열이면 빈 목록)
     * @return list<int> 글 ID (title_norm 오름차순)
     */
    public static function searchPostIds(int $boardId, string $normalizedKeyword, int $limit): array
    {
        if ($normalizedKeyword === '') {
            return [];
        }

        return WikiDoc::query()
            ->where('board_id', $boardId)
            ->where('title_norm', 'like', '%'.self::escapeLike($normalizedKeyword).'%')
            ->orderBy('title_norm')
            ->orderBy('post_id')
            ->limit(max(1, $limit))
            ->pluck('post_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * LIKE 패턴에 끼워 넣을 문자열을 이스케이프한다 — 와일드카드를 글자로 되돌린다.
     *
     * 코어 `PostRepository::escapeLikeKeyword()` 와 같은 방식이다. `\` 를 **먼저** 치환해야
     * `%` 를 바꾸며 붙인 `\` 가 다시 escape 되지 않는다(`str_replace` 는 배열을 순서대로 돈다).
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * 주어진 글 ID 중 **이 요청자에게 목록으로 보여도 되는 것**만 걸러 낸다.
     *
     * 코어 목록이 거는 것과 같은 두 가지를 건다: 삭제되지 않았을 것,
     * 그리고 `PermissionHelper::applyPermissionScope()` 의 범위 스코프(`self`·`role`).
     *
     * 비밀글은 **빼지 않는다** — 코어 목록도 비밀글 행은 보여 주고 본문 미리보기만 가린다
     * (`PostResource::getMaskedContentPreviewForList()`). 그 판정은 항목 변환기가 한다.
     *
     * 돌려주는 순서는 넘긴 순서 그대로다.
     *
     * @param  list<int>  $postIds  후보 글 ID (순서가 결과 순서가 된다)
     * @return list<int>
     */
    public static function visiblePostIds(string $slug, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $query = Post::query()
            ->whereIn('id', $postIds)
            ->whereNull('deleted_at');

        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.'.$slug.'.posts.read');

        $allowed = $query->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $allowed = array_flip($allowed);

        return array_values(array_filter(
            $postIds,
            static fn (int $id): bool => isset($allowed[$id])
        ));
    }

    /**
     * 본문에 자리표시(`[[#`)가 든 글의 ID — 봇 캐시를 비울 대상.
     *
     * 색인 표와 조인하지 **않는다.** 자리표시는 색인에 오르지 않는 글(답글 등)에서도
     * 치환되므로, 그 글의 봇 캐시도 같이 낡는다.
     *
     * `[[#` 에는 LIKE 특수문자(`%`·`_`·`\`)가 없어 그대로 패턴에 넣어도 안전하다.
     * 값은 바인딩으로 넘어간다.
     *
     * @param  int  $max  돌려줄 최대 건수
     * @return array{ids: list<int>, truncated: bool} 잘렸으면 `truncated` 가 참
     */
    public static function placeholderPostIds(int $boardId, int $max): array
    {
        $max = max(1, $max);

        $ids = DB::table(WikiVisibility::postsTable())
            ->where('board_id', $boardId)
            ->whereNull('deleted_at')
            ->where('content', 'like', '%[[#%')
            // 최근에 손댄 문서부터 비운다 — 상한에 걸려 잘릴 때 사람이 볼 확률이 높은 쪽을 남긴다.
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($max + 1)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [
            'ids' => array_slice($ids, 0, $max),
            'truncated' => count($ids) > $max,
        ];
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
