<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sirsoft\Board\Models\Post;

/**
 * 게시판 **목록 응답**이 쓰는 조회 — 어느 문서를 어떤 순서로 늘어놓을지 정한다.
 *
 * {@see \Plugins\G7\Light\Wiki\Support\Doc\DocListRenderer} 한 곳에서만 부른다.
 * 자리표시 목록({@see WikiDocListQuery})과 갈라 두는 이유는 **거는 조건이 다르기** 때문이다.
 *
 * ## 무엇을 거는가 — 코어 목록과 같은 것
 *
 * 조건은 {@see coreListConditions()} 한 곳에 있다. 코어 사용자 목록
 * (`PostRepository::buildSortedPostList()` 의 원글 조회)이 거는 것과 같은 넷이다.
 *
 * | 조건 | 코어 | 왜 |
 * |---|---|---|
 * | `deleted_at IS NULL` | 건다 | 지운 글 |
 * | `parent_id IS NULL` | 건다 (원글만) | 답글은 문서가 아니다. 코어는 답글을 부모 밑에 따로 붙인다 |
 * | `is_notice = false` (**대문 글은 예외**) | 건다 (공지는 따로 앞에 붙인다) | 문서 목록에 공지가 원글처럼 섞이면 순서가 무너진다 |
 * | 권한 범위 스코프 | 건다 | `self`·`role` 스코프를 코어 헬퍼가 그대로 판정한다 |
 *
 * **대문 글은 공지여도 목록에서 빠지지 않는다.** 운영자가 대문을 공지로 지정해 두는 것은
 * 있을 수 있는 구성이고, 그때 기본 목록(대문 1건)이 비어 버리면 게시판을 열 방법이 사라진다.
 * 예외는 공지 조건 **하나에만** 걸린다 — 삭제된 대문, 답글인 대문, 권한 밖의 대문은 그대로 빠진다.
 *
 * **`status` 와 `is_secret` 은 걸지 않는다 — 코어도 걸지 않는다.** 블라인드·비밀글은 행과
 * 제목은 보이고 본문 미리보기만 가리는 것이 이 모듈의 방식이고(`PostResource::
 * getMaskedContentPreviewForList()`), 그 판정은 항목 변환기가 한다. 자리표시 목록
 * ({@see WikiVisibility})은 반대로 둘을 빼므로, 두 조건 묶음을 섞어 쓰면 안 된다.
 *
 * ## 삭제글 포함 토글(`del`)
 *
 * 코어는 `manager` 권한 + `?del=1` 일 때만 삭제글을 목록에 넣는다. 이 확장은 **아직 그
 * 토글을 보지 않고 삭제글을 항상 뺀다**(2026-09-22 결정, 별도 작업). 그래서 관리자가 토글을
 * 켜도 위키 게시판 목록에는 삭제된 문서가 나오지 않는다.
 */
final class WikiBoardListQuery
{
    /**
     * 목록 응답이 한 번에 다룰 문서의 최대 수.
     *
     * 색인 자리표시의 상한({@see WikiDocListQuery::INDEX_LIMIT})과 **값은 같지만 다른
     * 상수다.** 전에는 목록 응답이 색인 상한을 빌려 썼는데, 그러면 한쪽 사정으로 값을 바꿀 때
     * 다른 쪽이 조용히 따라 움직인다.
     */
    public const LIST_LIMIT = 2000;

    /** 무작위 모드가 한 쪽에 늘어놓는 문서 수 (1쪽 고정이라 이것이 전부다) */
    public const RANDOM_COUNT = 20;

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
     * **거른 뒤에 자른다.** 전에는 색인 표만 보고 `LIMIT` 을 걸고 노출 판정을 뒤에 했다.
     * 그러면 상한에 걸린 게시판에서 ① 볼 자격이 있는 문서가 상한 뒤로 밀려 영영 안 나오고
     * ② "몇 건 이상" 판정이 거르기 전 건수로 서서 실제보다 많게 나온다.
     *
     * @param  string  $normalizedKeyword  정규화된 검색어 (빈 문자열이면 빈 목록)
     * @return list<int> 글 ID (title_norm 오름차순)
     */
    public static function searchPostIds(string $slug, int $boardId, string $normalizedKeyword, int $limit): array
    {
        if ($normalizedKeyword === '') {
            return [];
        }

        return self::postIds(
            self::candidates($slug, $boardId)
                ->where('d.title_norm', 'like', '%'.self::escapeLike($normalizedKeyword).'%')
                ->orderBy('d.title_norm')
                ->orderBy('d.post_id')
                ->limit(max(1, $limit))
        );
    }

    /**
     * 최근 수정 목록 — 색인 표의 `edited_at` 내림차순.
     *
     * 보조 정렬 키 `d.post_id DESC` 를 함께 준다. `edited_at` 은 초 단위라 일괄 수정에서
     * 동률이 생기는데, 보조 키가 없으면 페이지 경계에서 같은 문서가 두 번 나오거나 빠진다.
     * 자리표시 목록({@see WikiDocListQuery::recent()})도 같은 키 쌍을 쓰므로 대문의
     * "최근 수정" 단과 이 목록의 순서가 어긋나지 않는다.
     *
     * @return list<int> 글 ID (edited_at 내림차순)
     */
    public static function recentPostIds(string $slug, int $boardId, int $limit): array
    {
        return self::postIds(
            self::candidates($slug, $boardId)
                ->orderByDesc('d.edited_at')
                ->orderByDesc('d.post_id')
                ->limit(max(1, $limit))
        );
    }

    /**
     * 무작위 문서 N 건 — 서로 다른 문서만 나온다(같은 행을 두 번 뽑지 않는다).
     *
     * 뽑는 방식은 자리표시의 `[[#랜덤|N]]`({@see WikiDocListQuery::randomDocs()})과 같은
     * `ORDER BY RAND()` 다. 두 자리의 "무작위" 가 서로 다른 성격이 되지 않게 맞춘다.
     *
     * @return list<int> 글 ID (무작위 순서)
     */
    public static function randomPostIds(string $slug, int $boardId, int $limit): array
    {
        return self::postIds(
            self::candidates($slug, $boardId)
                ->inRandomOrder()
                ->limit(max(1, $limit))
        );
    }

    /**
     * 주어진 글 ID 중 **이 요청자에게 목록으로 보여도 되는 것**만 걸러 낸다.
     *
     * 대문 글 한 건을 담은 기본 목록과, 쿨다운으로 되쓰는 무작위 결과가 쓴다. 색인 표를
     * 보지 않고 코어 글 표만 보므로, 색인에 아직 줄이 없는 글도 대문으로 지정돼 있으면 나온다.
     *
     * 돌려주는 순서는 넘긴 순서 그대로다.
     *
     * @param  list<int>  $postIds  후보 글 ID (순서가 결과 순서가 된다)
     * @return list<int>
     */
    public static function visiblePostIds(string $slug, int $boardId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $query = self::coreListConditions(
            Post::query()->whereIn(WikiVisibility::postsTable().'.id', $postIds),
            $slug,
            $boardId,
            WikiVisibility::postsTable()
        );

        $allowed = array_flip(self::postIds($query));

        return array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $postIds),
            static fn (int $id): bool => isset($allowed[$id])
        ));
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
     * 후보 조회의 공통 뼈대 — 코어 글 표에 색인 표를 붙이고 코어 목록 조건을 건다.
     *
     * 색인 표를 `d` 로 붙이는 쪽이 **코어 글 표가 주(主)** 가 된다 — 권한 스코프 헬퍼가
     * 별칭 없는 `user_id` 를 걸기 때문이다. 색인 표에는 `user_id` 가 없어 모호해지지 않는다
     * (색인 표의 칸: `id`·`board_id`·`post_id`·`title`·`title_norm`·`edited_at`·타임스탬프 2개).
     * 같은 이유로 이 조회에서는 `created_at`·`updated_at`·`id` 를 **별칭 없이 쓰지 않는다**.
     */
    private static function candidates(string $slug, int $boardId): Builder
    {
        $posts = WikiVisibility::postsTable();

        $query = Post::query()
            ->join(WikiVisibility::docsTable().' as d', 'd.post_id', '=', $posts.'.id')
            ->where('d.board_id', $boardId);

        return self::coreListConditions($query, $slug, $boardId, $posts);
    }

    /**
     * 코어 사용자 목록이 거는 조건이 **적혀 있는 한 곳**.
     *
     * 범위 스코프는 코어 헬퍼를 그대로 부른다(부모 메서드 본문을 옮겨 적지 않는다).
     * 권한 식별자는 코어 `PostService::getPosts()` 가 사용자 컨텍스트에서 쓰는 것과 같다.
     *
     * 공지 조건만 **대문 글을 예외로 둔다**. 예외를 묶음(`where(function …)`) 안에 두는 것이
     * 중요하다 — `orWhere` 를 바깥에 풀어 놓으면 삭제·답글 조건까지 함께 무력화된다.
     *
     * @param  string  $posts  코어 글 표의 이름 또는 별칭
     */
    private static function coreListConditions(Builder $query, string $slug, int $boardId, string $posts): Builder
    {
        $exemptPostId = self::noticeExemptId(WikiBoardSettings::frontPostId($boardId));

        $query
            ->whereNull($posts.'.deleted_at')
            ->whereNull($posts.'.parent_id')
            ->where(static function (Builder $notice) use ($posts, $exemptPostId): void {
                $notice->where($posts.'.is_notice', false);

                if ($exemptPostId !== null) {
                    $notice->orWhere($posts.'.id', $exemptPostId);
                }
            });

        PermissionHelper::applyPermissionScope($query, 'sirsoft-board.'.$slug.'.posts.read');

        return $query;
    }

    /**
     * 공지 조건에서 예외로 둘 글 ID — **대문 글 하나뿐**이다.
     *
     * 대문이 없거나(`null`) 쓸 수 없는 값(0·음수)이면 예외가 없다 — 그때는 공지가 전부 빠진다.
     * 이 판정만 따로 떼어 둔 이유는, 쿼리를 타지 않고도 "대문만 예외" 라는 규칙을 시험할 수 있게
     * 하기 위해서다({@see \Plugins\G7\Light\Wiki\Tests\Unit\WikiBoardListQueryTest}).
     */
    public static function noticeExemptId(?int $frontPostId): ?int
    {
        return $frontPostId !== null && $frontPostId > 0 ? $frontPostId : null;
    }

    /**
     * 조회 결과를 글 ID 목록으로 옮긴다.
     *
     * 조인이 붙은 조회에서도 맞도록 **별칭을 붙인 컬럼**을 뜬다.
     *
     * @return list<int>
     */
    private static function postIds(Builder $query): array
    {
        return $query->pluck(WikiVisibility::postsTable().'.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
