<?php

namespace Plugins\G7\Light\Wiki\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Board\Rules\SlugUniqueRule;

/**
 * 새 위키 게시판 만들기 요청의 형태 검증.
 *
 * 게시판은 모듈 서비스를 바로 불러 만들므로 모듈의 게시판 생성 요청 검증기를 타지 않는다.
 * 그래서 이름·slug 규칙을 여기서 **같게** 선언한다(`StoreBoardRequest` 의 `name` 100자,
 * `slug` 형식·50자·예약어·중복). 중복·예약어 판정은 모듈 규칙 객체를 그대로 쓴다.
 * 권한은 라우트 미들웨어가 본다.
 */
class StoreWikiBoardRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/', new SlugUniqueRule],
            'author_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
