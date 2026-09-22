<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;

/**
 * 본문에서 뽑아 둔 표기 토큰 묶음 — 거기서 필요한 것만 꺼내 준다.
 *
 * 전에는 이 꺼내기가 미들웨어의 정적 메서드 두 개(`targetsOf()`·`placeholderArguments()`)와
 * 자리표시 존재를 보는 `foreach` 로 흩어져 있었다. 토큰을 다루는 일은 응답을 가공하는 일과
 * 층이 달라 여기로 모았다.
 */
final class TokenSet
{
    /**
     * @param  list<array<string, mixed>>  $tokens  {@see \Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter::tokens()}
     */
    public function __construct(private readonly array $tokens) {}

    /**
     * 그 종류의 대상 이름을 **등장 순서로** (중복 제거).
     *
     * @return list<string>
     */
    public function targetsOf(string $kind): array
    {
        $out = [];

        foreach ($this->tokens as $token) {
            if (($token['kind'] ?? '') !== $kind) {
                continue;
            }

            $target = (string) ($token['target'] ?? '');

            if ($target !== '' && ! in_array($target, $out, true)) {
                $out[] = $target;
            }
        }

        return $out;
    }

    /**
     * 그 자리표시의 인자를 **등장 순서로** (중복 제거, 빈 인자 제외).
     *
     * @return list<string>
     */
    public function placeholderArguments(string $name): array
    {
        $out = [];

        foreach ($this->tokens as $token) {
            if (($token['kind'] ?? '') !== WikiMarkupParser::KIND_PLACEHOLDER || ($token['name'] ?? '') !== $name) {
                continue;
            }

            $argument = $token['argument'] ?? null;

            if (is_string($argument) && trim($argument) !== '' && ! in_array(trim($argument), $out, true)) {
                $out[] = trim($argument);
            }
        }

        return $out;
    }

    /**
     * 자리표시가 하나라도 있는가 — 없으면 자리표시 디스패처를 만들지 않는다.
     */
    public function hasPlaceholder(): bool
    {
        foreach ($this->tokens as $token) {
            if (($token['kind'] ?? '') === WikiMarkupParser::KIND_PLACEHOLDER) {
                return true;
            }
        }

        return false;
    }
}
