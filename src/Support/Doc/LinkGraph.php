<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\WikiLinkGraphQuery;

/**
 * 보는 사람이 읽을 수 있는 문서들 사이의 링크 — `[[#외톨이]]`·`[[#필요한문서]]` 가 함께 쓴다.
 *
 * 링크 표기를 한 번 뜨고, 그 대상 이름들을 화면 링크 색과 **같은** 판정({@see LinkTargets})에
 * 한 번 넘긴다. 그 결과로
 *
 * - 연결된 문서 = 대상이 있고, 대상이 링크를 건 문서 자신이 아닌 것(자기 링크는 연결이 아니다)
 * - 빨간 이름 = 대상이 없는 것(화면에서 빨간 링크가 되는 이름)
 *
 * 을 가른다. 분류 소속·별칭 선언·연표는 링크 표기가 아니라 여기 들어오지 않는다. 자리표시가
 * 그린 링크도 표기 색인에 없으므로 들어오지 않는다.
 *
 * 조회: 표기 1 + 판정 최대 2.
 */
final class LinkGraph
{
    /**
     * @param  array<int, true>  $linked  연결된 문서 ID
     * @param  list<string>  $red  빨간 이름(정규화)
     * @param  array<int, string>  $targets  표기 ID => 원문 이름
     */
    private function __construct(
        private readonly array $linked,
        private readonly array $red,
        private readonly array $targets,
    ) {}

    public static function build(int $boardId, SecretScope $scope): self
    {
        $refs = WikiLinkGraphQuery::linkRefs($boardId, $scope);

        $names = array_values(array_unique(array_column($refs, 'target_norm')));
        $resolved = LinkTargets::resolve($boardId, $names);

        $linked = [];
        $red = [];
        $targets = [];

        foreach ($refs as $ref) {
            $targets[$ref['id']] = $ref['target'];
            $doc = $resolved->target($ref['target_norm']);

            if ($doc === null) {
                $red[$ref['target_norm']] = true;
            } elseif ($doc['post_id'] !== $ref['post_id']) {
                $linked[$doc['post_id']] = true;
            }
        }

        return new self($linked, array_keys($red), $targets);
    }

    /** @return list<int> */
    public function linkedIds(): array
    {
        return array_keys($this->linked);
    }

    /** @return list<string> */
    public function redNames(): array
    {
        return array_map('strval', $this->red);
    }

    /** 표기 ID 의 원문 이름 (없으면 빈 문자열) */
    public function targetOf(int $refId): string
    {
        return $this->targets[$refId] ?? '';
    }
}
