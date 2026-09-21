<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 본문 HTML 의 **텍스트 노드 안에서만** 위키 표기를 찾아 바꾸는 순수 클래스
 * (DB·HTTP·설정 접근 없음). 주어진 HTML 문자열 하나만 다룬다.
 *
 * ## 왜 DOM 인가
 *
 * `[[문서명]]` 은 사람이 본문에 적는 글자다. 이미 링크 안(`<a>`)이거나 코드 블록
 * (`<code>`·`<pre>`) 안이면 **글자 그대로 보여야** 한다. 문자열 정규식으로는 "지금
 * 어느 태그 안인가" 를 안정적으로 알 수 없어 DOM 으로 읽는다.
 *
 * 대신 DOM 은 문서 전체를 재직렬화한다 — 속성 따옴표·빈 요소 닫는 방식·엔티티 표기가
 * 파서 취향으로 바뀐다. 그래서 {@see hasMarkup()} 가 거짓이면 **입력 문자열을 그대로
 * 돌려준다**. 위키 표기가 없는 본문은 한 바이트도 달라지지 않는다.
 *
 * ## 다루지 않는 것
 *
 * - 여러 텍스트 노드에 걸친 표기(`[[문서<b>명</b>]]`) — 표기가 아니다.
 * - `<a>`·`<code>`·`<pre>` 의 자손 텍스트 — 건너뛴다.
 */
final class HtmlLinkRewriter
{
    /** 이 태그의 자손 텍스트는 건드리지 않는다 */
    public const SKIP_TAGS = ['a', 'code', 'pre'];

    /** 감싸개 요소 id — 본문을 다시 꺼낼 때 기준이 된다 */
    private const ROOT_ID = 'g7lw-root-8f31';

    private ?\DOMDocument $doc = null;

    private ?\DOMElement $root = null;

    /** @var list<array{node: \DOMText, tokens: list<array<string, mixed>>}> */
    private array $matches = [];

    private bool $loaded = false;

    /**
     * @param  string  $html  본문 HTML
     */
    public function __construct(private readonly string $html) {}

    /**
     * 바꿀 표기가 하나라도 있는가. 거짓이면 {@see rewrite()} 는 입력을 그대로 돌려준다.
     */
    public function hasMarkup(): bool
    {
        $this->load();

        return $this->matches !== [];
    }

    /**
     * 문서 링크 표기의 대상 제목 목록 (원문 그대로, 중복 제거, 등장 순서).
     *
     * 호출부는 이 목록을 정규화해 **조회 1회**로 존재 여부를 판정한다.
     *
     * @return list<string>
     */
    public function linkTargets(): array
    {
        $this->load();

        $targets = [];

        foreach ($this->matches as $match) {
            foreach ($match['tokens'] as $token) {
                if ($token['kind'] !== WikiMarkupParser::KIND_LINK) {
                    continue;
                }

                if (! in_array($token['target'], $targets, true)) {
                    $targets[] = $token['target'];
                }
            }
        }

        return $targets;
    }

    /**
     * 자리표시(`[[#…]]`) 표기가 하나라도 있는가.
     *
     * 호출부는 이것이 거짓이면 자리표시 렌더러를 **아예 만들지 않는다** — 렌더러를 만들면
     * 읽기 권한 판정이 한 번 더 도므로, 자리표시가 없는 문서에는 그 비용을 얹지 않는다.
     */
    public function hasPlaceholder(): bool
    {
        $this->load();

        foreach ($this->matches as $match) {
            foreach ($match['tokens'] as $token) {
                if ($token['kind'] === WikiMarkupParser::KIND_PLACEHOLDER) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 표기를 바꿔 본문 HTML 을 돌려줍니다.
     *
     * @param  callable(array<string, mixed>): ?string  $resolver  토큰 → 대체 HTML.
     *                                       `null` 을 돌려주면 그 표기는 **원문 그대로** 남는다.
     * @return string 가공된 HTML (바꿀 것이 없으면 입력과 바이트가 같다)
     */
    public function rewrite(callable $resolver): string
    {
        $this->load();

        if ($this->matches === [] || $this->doc === null || $this->root === null) {
            return $this->html;
        }

        $changed = false;

        foreach ($this->matches as $match) {
            $parts = $this->buildParts($match['node']->nodeValue ?? '', $match['tokens'], $resolver, $replaced);

            if (! $replaced) {
                continue;
            }

            $this->replaceNode($match['node'], $parts);
            $changed = true;
        }

        if (! $changed) {
            return $this->html;
        }

        return $this->serialize();
    }

    /**
     * 텍스트 한 덩이를 "그대로 둘 글자" 와 "대체 HTML" 조각으로 자릅니다.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @param  callable(array<string, mixed>): ?string  $resolver
     * @param  bool|null  $replaced  하나라도 바꿨는지 (출력)
     * @return list<array{type: string, value: string}>
     */
    private function buildParts(string $text, array $tokens, callable $resolver, ?bool &$replaced): array
    {
        $replaced = false;
        $parts = [];
        $cursor = 0;

        foreach ($tokens as $token) {
            $html = $resolver($token);

            if ($html === null) {
                continue;
            }

            if ($token['offset'] > $cursor) {
                $parts[] = ['type' => 'text', 'value' => substr($text, $cursor, $token['offset'] - $cursor)];
            }

            $parts[] = ['type' => 'html', 'value' => $html];
            $cursor = $token['offset'] + $token['length'];
            $replaced = true;
        }

        if ($replaced && $cursor < strlen($text)) {
            $parts[] = ['type' => 'text', 'value' => substr($text, $cursor)];
        }

        return $parts;
    }

    /**
     * 텍스트 노드를 조각 목록으로 바꿔 끼웁니다.
     *
     * @param  list<array{type: string, value: string}>  $parts
     */
    private function replaceNode(\DOMText $node, array $parts): void
    {
        $doc = $this->doc;

        if ($doc === null || $node->parentNode === null) {
            return;
        }

        $fragment = $doc->createDocumentFragment();

        foreach ($parts as $part) {
            if ($part['type'] === 'text') {
                $fragment->appendChild($doc->createTextNode($part['value']));

                continue;
            }

            foreach ($this->parseFragment($part['value']) as $imported) {
                $fragment->appendChild($imported);
            }
        }

        $node->parentNode->replaceChild($fragment, $node);
    }

    /**
     * 우리가 만든 HTML 조각을 이 문서의 노드 목록으로 바꿉니다.
     *
     * 입력은 언제나 이 플러그인이 조립한 문자열이다(사용자 입력은 조립 시점에 이스케이프됨).
     *
     * @return list<\DOMNode>
     */
    private function parseFragment(string $html): array
    {
        $doc = $this->doc;

        if ($doc === null) {
            return [];
        }

        $sub = self::loadDocument($html);
        $root = self::rootOf($sub);

        if ($root === null) {
            return [];
        }

        $nodes = [];

        foreach (iterator_to_array($root->childNodes) as $child) {
            $nodes[] = $doc->importNode($child, true);
        }

        return $nodes;
    }

    /**
     * 본문을 한 번만 읽어 대상 텍스트 노드와 토큰을 모읍니다.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if ($this->html === '' || ! str_contains($this->html, '[[')) {
            return;
        }

        $this->doc = self::loadDocument($this->html);
        $this->root = self::rootOf($this->doc);

        if ($this->root === null) {
            return;
        }

        foreach ($this->collectTextNodes($this->root) as $node) {
            $tokens = WikiMarkupParser::tokenize($node->nodeValue ?? '');

            if ($tokens === []) {
                continue;
            }

            $this->matches[] = ['node' => $node, 'tokens' => $tokens];
        }
    }

    /**
     * 건너뛸 태그 안이 아닌 텍스트 노드를 모읍니다 (문서 순서).
     *
     * @return list<\DOMText>
     */
    private function collectTextNodes(\DOMNode $node): array
    {
        $found = [];

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                if (! $child instanceof \DOMCdataSection) {
                    $found[] = $child;
                }

                continue;
            }

            if (! $child instanceof \DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->tagName), self::SKIP_TAGS, true)) {
                continue;
            }

            foreach ($this->collectTextNodes($child) as $deep) {
                $found[] = $deep;
            }
        }

        return $found;
    }

    /**
     * 가공된 본문을 다시 문자열로 꺼냅니다 (감싸개 요소는 빼고).
     */
    private function serialize(): string
    {
        $doc = $this->doc;
        $root = $this->root;

        if ($doc === null || $root === null) {
            return $this->html;
        }

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * HTML 조각을 UTF-8 로 읽어 들입니다. 감싸개 `div` 하나를 씌워 조각의 경계를 잡는다.
     */
    private static function loadDocument(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $previous = libxml_use_internal_errors(true);

        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            .'</head><body><div id="'.self::ROOT_ID.'">'.$html.'</div></body></html>',
            LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    /**
     * 감싸개 요소를 찾습니다. `getElementById` 는 DTD 없이는 믿을 수 없어 XPath 로 찾는다.
     */
    private static function rootOf(\DOMDocument $doc): ?\DOMElement
    {
        $found = (new \DOMXPath($doc))->query('//div[@id="'.self::ROOT_ID.'"]');

        if ($found === false || $found->length === 0) {
            return null;
        }

        $node = $found->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }
}
