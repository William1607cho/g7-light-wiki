<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiCategory;
use Plugins\G7\Light\Wiki\Support\WikiCategoryIndexQuery;
use Plugins\G7\Light\Wiki\Support\WikiDocListQuery;

/**
 * `[[#분류색인]]` 의 항목 — 분류 이름과 그 분류 문서(없으면 null).
 *
 * ## 합치는 규칙
 *
 * - 대상 = ⓐ 본문 분류 표기의 이름 ∪ ⓑ `분류:` 문서 제목의 나머지 이름 (정규화 이름으로 중복 제거)
 * - 표시 이름 = ⓑ 가 있으면 그 문서 제목에서 접두어를 뗀 것, ⓐ 만 있으면 표기 ID 가 가장 작은 원문
 * - 링크 대상 = `분류:<이름>` 문서. 색은 화면 링크와 같은 판정({@see LinkTargets} — 제목 → 별칭)
 *
 * 순서·묶음은 색인과 같은 조립기가 한다(정규화 이름 기준). 개수 상한도 색인과 같다.
 *
 * 조회: 이름 원천 2 + 판정 최대 2.
 */
final class CategoryIndex
{
    /**
     * @return list<array{title: string, title_norm: string, post_id: int|null}>
     */
    public static function build(int $boardId): array
    {
        $names = [];

        foreach (WikiCategoryIndexQuery::categoryDocs($boardId) as $doc) {
            $key = WikiCategory::nameOf($doc['title_norm']);

            if ($key !== '' && ! isset($names[$key])) {
                $names[$key] = self::docName($doc['title'], $key);
            }
        }

        foreach (WikiCategoryIndexQuery::usedNames($boardId) as $ref) {
            $names[$ref['target_norm']] ??= $ref['target'];
        }

        $keys = array_map('strval', array_keys($names));
        sort($keys, SORT_STRING);
        $keys = array_slice($keys, 0, WikiDocListQuery::INDEX_LIMIT);

        $titleOf = [];

        foreach ($keys as $key) {
            $titleOf[$key] = TitleNormalizer::normalize(WikiCategory::title($key));
        }

        $targets = LinkTargets::resolve($boardId, array_values($titleOf));

        return array_map(static fn (string $key): array => [
            'title' => $names[$key],
            'title_norm' => $key,
            'post_id' => $targets->target($titleOf[$key])['post_id'] ?? null,
        ], $keys);
    }

    /**
     * 분류 문서 제목에서 접두어를 뗀 이름 — 제목이 접두어 그대로 시작하지 않으면(전각 쌍점 등)
     * 정규화 이름을 쓴다.
     */
    private static function docName(string $title, string $key): string
    {
        $title = trim($title);

        if (! str_starts_with($title, WikiCategory::PREFIX)) {
            return $key;
        }

        $name = trim(substr($title, strlen(WikiCategory::PREFIX)));

        return $name === '' ? $key : $name;
    }
}
