<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\EventKey;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 표기 토큰 하나를 대체 HTML 로 바꾸는 판정기 — {@see \Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter::rewrite()} 가 부른다.
 *
 * 전에는 미들웨어가 클로저로 만들어 넘겼다. `use` 로 끌어온 값이 넷이라 무엇에 기대는지가
 * 클로저 머리에만 적혀 있었다.
 *
 * ## 종류별 처리
 *
 * | 종류 | 결과 |
 * |---|---|
 * | 분류·별칭 | **빈 문자열** — 본문에서 지우고 문서 아래 자동 영역으로 옮겨 간다 |
 * | 사건 | 설명 글자만 남긴다. 키 형식이 틀리면 `null`(원문 유지) |
 * | 자리표시 | 디스패처에 넘긴다 (없으면 `null`) |
 * | 문서 링크 | 실제 제목 → 별칭 순으로 찾아 파란 링크. 없으면 빨간 링크나 빨간 글자 |
 * | 그 밖 | `null` — 원문을 그대로 둔다 |
 */
final class LinkResolver
{
    public function __construct(
        private readonly DocTarget $target,
        private readonly DocContext $context,
        private readonly DocGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $token
     */
    public function __invoke(array $token): ?string
    {
        $kind = $token['kind'] ?? '';

        // 분류·별칭은 본문에서 **지운다** — 문서 아래 자동 영역으로 옮겨 간다.
        if ($kind === WikiMarkupParser::KIND_CATEGORY || $kind === WikiMarkupParser::KIND_ALIAS) {
            return '';
        }

        if ($kind === WikiMarkupParser::KIND_EVENT) {
            return self::eventHtml($token);
        }

        if ($kind === WikiMarkupParser::KIND_PLACEHOLDER) {
            return $this->context->renderer?->render($token);
        }

        if ($kind !== WikiMarkupParser::KIND_LINK) {
            return null;
        }

        $name = (string) $token['target'];
        $normalized = TitleNormalizer::normalize($name);

        if (! TitleNormalizer::isRegistrable($normalized)) {
            return null;
        }

        $label = $token['label'] ?? $name;

        // 해석 순서: ① 실제 제목 ② 별칭. 별칭으로 이어진 링크도 파란 링크이고 주소는 본 문서다.
        $doc = $this->context->found[$normalized] ?? $this->context->alias[$normalized] ?? null;

        if ($doc !== null) {
            return WikiHtml::link(WikiUrl::post($this->target->slug, $doc['post_id']), $label);
        }

        return $this->gate->canWrite
            ? WikiHtml::newLink(WikiUrl::newDoc($this->target->boardId, $name), $label)
            : WikiHtml::newText($label);
    }

    /**
     * `[[연표:키|설명]]` 이 있던 자리 — 설명 글자만 남는다.
     *
     * 형식이 틀린 키는 사건이 아니므로 **원문 그대로** 둔다(`null`). 사람이 오타를 알아채려면
     * 적은 것이 그대로 보여야 한다.
     *
     * @param  array<string, mixed>  $token
     */
    private static function eventHtml(array $token): ?string
    {
        $key = (string) ($token['target'] ?? '');

        if (! EventKey::isValid($key)) {
            return null;
        }

        $label = $token['label'] ?? null;

        return WikiHtml::eventInline(is_string($label) && $label !== '' ? $label : $key);
    }
}
