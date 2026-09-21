<?php

namespace Plugins\G7\Light\Wiki\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 위키 게시판 설정 저장 요청의 **형태**를 검증한다.
 *
 * 권한은 라우트 미들웨어(`permission:admin,core.plugins.update`)가 본다.
 * 게시판·대문 글이 실제로 있는지는 형태가 맞은 뒤 컨트롤러가 조회해서 본다.
 */
class UpdateWikiBoardsRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 미들웨어가 검사하므로 true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 빈 배열(= 위키 게시판 없음)도 받아야 하므로 `required` 대신 `present` 를 쓴다.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'wiki_boards' => ['present', 'array', 'max:'.WikiBoardSettings::MAX_BOARDS],
            'wiki_boards.*.board_id' => ['required', 'integer', 'min:1'],
            'wiki_boards.*.front_post_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * 정리된 설정 목록.
     *
     * @return list<array{board_id: int, front_post_id: ?int}>
     */
    public function rows(): array
    {
        return WikiBoardSettings::normalize($this->validated('wiki_boards', []));
    }
}
