<?php

namespace Plugins\G7\Light\Wiki\Support\Placeholders;

/**
 * 자리표시 하나를 HTML 로 바꾸는 것들의 공통 인터페이스.
 *
 * 디스패처({@see PlaceholderRenderer})는 이 인터페이스만 알고, 이름으로 찾아 부르기만 한다.
 * 새 자리표시를 더할 때 디스패처를 고치지 않는다 — 구현 하나를 더해 등록하면 된다.
 */
interface Renderer
{
    /**
     * 이 렌더러가 맡는 자리표시 이름.
     *
     * 최근수정·최근작성처럼 만드는 모양이 같은 것은 한 렌더러가 둘을 맡는다.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * 자리표시 하나를 HTML 로 바꿉니다.
     *
     * @param  string  $name  자리표시 이름 ({@see names()} 가 돌려준 것 중 하나)
     * @param  mixed  $argument  `|` 뒤의 인자 (없으면 null)
     * @return string|null 대체 HTML. `null` 이면 **원문을 그대로 둔다**.
     */
    public function render(string $name, mixed $argument): ?string;
}
