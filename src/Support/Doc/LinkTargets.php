<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\WikiDocLookup;
use Plugins\G7\Light\Wiki\Support\WikiRefQuery;

/**
 * 링크 대상 판정 — 이름이 어느 문서를 가리키는가. **화면 링크 색과 목록이 같은 규칙을 쓰는 한 곳.**
 *
 * 순서: ① 실제 제목({@see WikiDocLookup::resolve()} — 가시성 필터 없음, 색인 표에 줄이 있으면
 * 그 제목은 임자가 있다) ② 제목으로 못 찾은 이름만 별칭({@see WikiRefQuery::aliasOwners()} —
 * 보이는 글의 별칭만, 같은 별칭은 작은 post_id 가 이긴다).
 *
 * 전에는 이 두 조회가 {@see DocContext::gather()} 안에 적혀 있었다. `[[#외톨이]]`·`[[#필요한문서]]`
 * 가 같은 판정을 써야 해서 떼어 냈다 — 두 곳에 적으면 한쪽만 고쳐 색과 목록이 갈릴 수 있다.
 */
final class LinkTargets
{
    /**
     * @param  array<string, array{post_id: int, title: string}>  $found  정규화 제목 → 문서
     * @param  array<string, array{post_id: int, title: string}>  $alias  정규화 별칭 → 문서
     */
    private function __construct(
        public readonly array $found,
        public readonly array $alias,
    ) {}

    /**
     * 이름들을 판정한다. 조회 최대 2회(제목 1 + 남은 이름이 있을 때 별칭 1).
     *
     * @param  list<string>  $titleNames  제목으로만 찾을 이름(정규화) — 분류 줄처럼 별칭을 보지 않는 것
     * @param  list<string>  $linkNames  제목 → 별칭 순으로 찾을 링크 대상(정규화)
     */
    public static function resolve(int $boardId, array $linkNames, array $titleNames = []): self
    {
        $ask = array_values(array_unique(array_merge($linkNames, $titleNames)));

        $found = $ask === [] ? [] : WikiDocLookup::resolve($boardId, $ask);

        $unresolved = array_values(array_unique(array_filter(
            $linkNames,
            static fn (string $name): bool => ! isset($found[$name])
        )));

        $alias = $unresolved === [] ? [] : WikiRefQuery::aliasOwners($boardId, $unresolved);

        return new self($found, $alias);
    }

    /**
     * 링크 대상 문서 — 없으면 null(빨간 링크).
     *
     * @return array{post_id: int, title: string}|null
     */
    public function target(string $normalized): ?array
    {
        return $this->found[$normalized] ?? $this->alias[$normalized] ?? null;
    }
}
