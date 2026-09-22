<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Http\JsonResponse;
use Plugins\G7\Light\Wiki\Support\DocFooterBuilder;
use Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiLabels;

/**
 * 글 상세 응답 하나를 가공한다 — 본문의 표기를 바꾸고 문서 뒤에 자동 영역을 붙인다.
 *
 * 미들웨어에서 떼어 냈다. 미들웨어는 "위키 게시판인가" 를 보고 이쪽으로 넘기는 일만 한다.
 *
 * ## 위키 게시판이면 본문을 안 바꿔도 응답을 되쓴다 (2026-09-22 변경)
 *
 * 전에는 "본문이 바뀌지 않으면 응답 객체를 건드리지 않는다" 가 원칙이었다. 지금은
 * **위키 게시판이면 언제나** 게시판 정보에 {@see WikiBoardFlag} 의 칸을 얹는다 — 화면이
 * 작성자·이전글/다음글을 숨기고 목록 버튼을 대문으로 보내려면 그 값이 **모든 문서에서**
 * 있어야 하기 때문이다. 본문이 평문(`content_mode !== 'html'`)이거나 표기가 하나도 없는
 * 문서에서만 값이 빠지면, 같은 게시판인데 문서마다 화면이 달라진다.
 *
 * 바뀌는 범위는 그 칸 하나다. 본문 치환·자동 영역 규칙은 전과 같고, 위키가 **아닌**
 * 게시판에는 미들웨어가 여기까지 오지 않으므로 응답 바이트가 설치 전과 같다.
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

        $data['data'] = WikiBoardFlag::withWiki(
            $data['data'],
            WikiBoardFlag::pageValue(WikiBoardSettings::frontPostId($this->boardId))
        );

        $body = $data['data']['content'] ?? null;

        if (is_string($body) && ($data['data']['content_mode'] ?? 'text') === 'html') {
            $data['data']['content'] = $this->rewrite($body, $data['data']);
        }

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다.
        $response->setData($data);

        return $response;
    }

    /**
     * 본문 하나를 위키 표기로 바꾸고 자동 영역을 붙인다.
     *
     * @param  array<string, mixed>  $post  상세 응답의 글 부분
     */
    private function rewrite(string $body, array $post): string
    {
        $target = new DocTarget(
            $this->slug,
            $this->boardId,
            (int) ($post['id'] ?? 0),
            TitleNormalizer::normalize((string) ($post['title'] ?? '')),
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

        return $rewritten.$footer;
    }
}
