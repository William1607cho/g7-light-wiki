<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Helpers\PermissionHelper;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Light\Wiki\Models\WikiDoc;

/**
 * 게시판 **목록 응답**이 쓰는 조회 — 제목 검색과, 요청자별 노출 판정.
 *
 * 둘 다 {@see \Plugins\G7\Light\Wiki\Support\Doc\DocListRenderer} 한 곳에서만 부른다.
 * 자리표시 목록({@see WikiDocListQuery})과 갈라 두는 이유는 **거는 조건이 다르기** 때문이다:
 * 자리표시 목록은 "남에게 보여도 되는 문서"({@see WikiVisibility})를 걸지만, 목록 응답은
 * 코어 목록과 같은 것 — 삭제 여부와 `PermissionHelper` 범위 스코프 — 을 건다.
 */
final class WikiDocSearchQuery
{
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
}
