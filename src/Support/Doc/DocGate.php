<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Plugins\G7\Light\Wiki\Support\WikiGate;

/**
 * 이 요청자가 이 게시판에서 무엇을 할 수 있는가 — 한 요청에 한 번만 판정한다.
 *
 * ## 두 판정이 쓰이는 자리가 다르다
 *
 * - `canRead`: **자동 영역과 자리표시**를 붙일지. 목록에 남의 문서 제목이 실리기 때문이다.
 *   자기 본문은 코어가 이미 판정해 여기까지 왔으므로 본문 치환은 이것과 무관하다.
 * - `canWrite`: 없는 문서를 **빨간 링크**(작성 화면으로)로 줄지, 빨간 **글자**로만 줄지.
 */
final class DocGate
{
    public function __construct(
        public readonly bool $canRead,
        public readonly bool $canWrite,
    ) {}

    /**
     * 요청자를 보고 판정한다.
     *
     * 이 요청은 SPA 가 보내는 XHR 이라 토큰이 실려 있다 — 요청자를 믿고 판정할 수 있다.
     * (브라우저 전체 이동으로 들어오는 주소에서는 토큰이 없어 이 판정을 쓸 수 없다.)
     */
    public static function of(string $slug, ?object $user): self
    {
        return new self(
            WikiGate::canRead($slug, $user),
            WikiGate::canWrite($slug, $user),
        );
    }
}
