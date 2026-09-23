<?php

namespace Plugins\G7\Light\Wiki\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 빈 게시판 위키화 요청의 형태 검증. 게시판은 경로로 받는다.
 * 위키화할 수 있는지는 형태가 맞은 뒤 트랜잭션 안에서 판정한다.
 */
class ConvertWikiBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'author_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
