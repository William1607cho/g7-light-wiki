<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Database\Query\Builder;
use Modules\Sirsoft\Board\Models\Post;
use Plugins\G7\Light\Wiki\Models\WikiDoc;
use Plugins\G7\Light\Wiki\Models\WikiRef;

/**
 * "남에게 보여도 되는 문서" 의 조건이 사는 **한 곳**.
 *
 * 이 조건은 두 조회 계층이 함께 쓴다 — {@see WikiDocQuery} 의 후보 조회와
 * {@see WikiRefQuery} 의 표기 조회다. 전에는 두 곳에 같은 네 줄이 따로 적혀 있어서,
 * 한쪽만 고치면 비밀글이 한쪽 목록에서만 새는 모양이 될 수 있었다.
 *
 * ## 빼는 것 네 가지
 *
 * | 조건 | 왜 |
 * |---|---|
 * | `deleted_at IS NULL` | 지운 글 |
 * | `status = 'published'` | 게시 상태가 아닌 글 (블라인드 포함) |
 * | `is_secret = 0` | **비밀글** — 제목만으로도 그 글의 존재와 내용이 드러난다 |
 * | `parent_id IS NULL` | 답글. 문서가 아니다(제목이 "Re: …" 로 겹친다) |
 *
 * 붙이는 순서는 전과 같다. 결과는 순서와 무관하지만, 만들어지는 SQL 문자열이 달라지면
 * `WikiRefQuery::limited()` 가 넘침을 셀 때 감싸는 서브쿼리도 달라져 비교가 번거로워진다.
 *
 * ## 여기서 판정하지 않는 것
 *
 * - **문서 링크 색**(파란/빨간)은 이 조건을 쓰지 않는다. 색인 표에 줄이 있으면 그 제목은
 *   이미 임자가 있는 것이고, 실제로 볼 수 있는지는 코어가 상세 화면에서 판정한다.
 * - **자기 화면의 자기 분류 줄·별칭 줄**도 쓰지 않는다. 그 글을 이미 열어 본 사람에게
 *   자기 것을 보이는 일이라 가릴 것이 없다.
 * - **봇 캐시 무효화**도 쓰지 않는다. 캐시를 비우는 일은 노출과 무관하다.
 */
final class WikiVisibility
{
    /**
     * 코어 글 표에 "보여도 되는 문서" 조건을 건다.
     *
     * @param  string  $posts  코어 글 표의 별칭 (조회마다 `p` 로 붙인다)
     */
    public static function apply(Builder $query, string $posts = 'p'): Builder
    {
        return $query
            ->whereNull($posts.'.deleted_at')
            ->where($posts.'.status', 'published')
            ->where($posts.'.is_secret', 0)
            ->whereNull($posts.'.parent_id');
    }

    /** 문서 색인 표 이름 */
    public static function docsTable(): string
    {
        return (new WikiDoc)->getTable();
    }

    /** 표기 색인 표 이름 */
    public static function refsTable(): string
    {
        return (new WikiRef)->getTable();
    }

    /** 코어 글 표 이름 */
    public static function postsTable(): string
    {
        return (new Post)->getTable();
    }
}
