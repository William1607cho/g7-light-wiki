<?php

namespace Plugins\G7\Light\Wiki\Support;

use Illuminate\Support\Facades\DB;
use Plugins\G7\Light\Wiki\Models\WikiDoc;

/**
 * 이름이나 본문으로 **문서를 지목해 찾는** 조회.
 *
 * 목록을 만드는 {@see WikiDocListQuery} 와 달리 여기 두 조회는 "무엇을 보여 줄까" 가 아니라
 * "이것이 어느 글인가" 를 묻는다. 그래서 후보 조회의 뼈대(정렬·개수·가시성 필터)를 쓰지 않고
 * 각자 필요한 조건만 건다.
 *
 * - {@see resolve()} 는 정규화 제목으로 색인 표를 본다. 가시성 필터를 **걸지 않는다** —
 *   색인 표에 줄이 있으면 그 제목은 이미 임자가 있는 것이고(링크 색 판정), 실제로 볼 수
 *   있는지는 코어가 상세 화면에서 판정한다.
 * - {@see placeholderPostIds()} 는 코어 글 표만 본다. 봇 캐시를 비우는 일은 노출과 무관해
 *   가시성 조건을 걸지 않는다({@see WikiVisibility} 머리말 참고).
 */
final class WikiDocLookup
{
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
}
