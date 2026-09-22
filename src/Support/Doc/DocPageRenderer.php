<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Http\JsonResponse;
use Plugins\G7\Light\Wiki\Support\DocFooterBuilder;
use Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiLabels;

/**
 * 글 상세 응답 하나를 가공한다 — 본문의 표기를 바꾸고 문서 뒤에 자동 영역을 붙인다.
 *
 * 미들웨어에서 떼어 냈다. 미들웨어는 "위키 게시판인가" 를 보고 이쪽으로 넘기는 일만 한다.
 *
 * ## 바꾸지 않는 경우 — 응답 객체를 건드리지 않는다
 *
 * 본문이 문자열이 아니거나 `content_mode` 가 `html` 이 아니면 원본 응답을 그대로 돌려준다.
 * 가공 결과가 원본과 같아도 그렇다. 그럴 때 응답 바이트는 설치 전과 같다.
 *
 * ## `content_mode` 를 보는 이유
 *
 * 상세 응답의 `data.content` 는 저장된 문자열 그대로이고, `content_mode` 가 `html` 이
 * 아니면 방문자 화면과 봇 SSR 이 **본문을 통째로 이스케이프해 평문으로** 그린다.
 * 그 상태에서 `<a>` 를 끼워 넣으면 태그가 글자로 보인다. 그래서 HTML 모드에서만 바꾼다.
 *
 * ## 표기가 없어도 붙는 것이 있다
 *
 * 역링크와 분류 소속 목록은 본문에 `[[` 가 없어도 붙을 수 있다(남이 이 문서를 가리켰거나,
 * 이 문서가 분류 문서인 경우). 그래서 표기가 없는 본문도 자동 영역 조립까지는 간다.
 */
final class DocPageRenderer
{
    public function __construct(
        private readonly string $slug,
        private readonly int $boardId,
        private readonly ?object $user,
    ) {}

    public function apply(JsonResponse $response): JsonResponse
    {
        $data = $response->getData(true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return $response;
        }

        $body = $data['data']['content'] ?? null;

        if (! is_string($body)) {
            return $response;
        }

        if (($data['data']['content_mode'] ?? 'text') !== 'html') {
            return $response;
        }

        $target = new DocTarget(
            $this->slug,
            $this->boardId,
            (int) ($data['data']['id'] ?? 0),
            TitleNormalizer::normalize((string) ($data['data']['title'] ?? '')),
        );

        $rewriter = new HtmlLinkRewriter($body);
        $gate = DocGate::of($this->slug, $this->user);
        $labels = WikiLabels::fromLang();

        // 본문 치환과 자동 영역이 쓸 것을 한 자리에서 모아 온다.
        $context = DocContext::gather($target, new TokenSet($rewriter->tokens()), $gate, $labels);

        $rewritten = $rewriter->hasMarkup()
            ? $rewriter->rewrite(new LinkResolver($target, $context, $gate), pruneEmptyBlocks: true)
            : $body;

        // 자동 영역은 요청자가 이 게시판을 읽을 수 있을 때만 붙인다 — 목록에 남의 문서 제목이
        // 실리기 때문이다. 자기 본문은 코어가 이미 판정해 여기까지 왔다.
        $footer = $gate->canRead
            ? DocFooterBuilder::build($this->slug, $context->footer, $labels)
            : '';

        $final = $rewritten.$footer;

        if ($final === $body) {
            return $response;
        }

        $data['data']['content'] = $final;

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다 — 본문 밖은 바이트가 같다.
        $response->setData($data);

        return $response;
    }
}
