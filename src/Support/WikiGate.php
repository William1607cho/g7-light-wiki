<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * 게시판 권한 판정 — 코어 `PermissionMiddleware` 와 같은 결론·같은 응답을 낸다.
 *
 * 이 플러그인의 API 라우트에는 권한 미들웨어를 걸 수 없다(게시판이 요청 인자로 오므로
 * 라우트 정의 시점에 권한 이름을 알 수 없다). 그래서 컨트롤러가 이 게이트로 판정한다.
 * 판정 결과는 요청 단위로 캐시되므로(회원=인스턴스 캐시, 비회원=guest 역할 캐시) 호출
 * 횟수가 질의 수로 이어지지 않는다.
 *
 * **회원에게 401 을 주면 안 된다** — 프론트 ApiClient 가 토큰 만료로 보고 강제 로그아웃한다.
 */
final class WikiGate
{
    /**
     * 글 읽기 권한.
     */
    public static function canRead(string $slug, ?object $user): bool
    {
        return PermissionHelper::check("sirsoft-board.{$slug}.posts.read", $user);
    }

    /**
     * 글쓰기 권한.
     */
    public static function canWrite(string $slug, ?object $user): bool
    {
        return PermissionHelper::check("sirsoft-board.{$slug}.posts.write", $user);
    }

    /**
     * 권한이 없으면 코어와 같은 형식으로 중단한다 (비회원 401 / 회원 403).
     *
     * @throws HttpResponseException
     */
    public static function assert(Request $request, string $slug, string $suffix): void
    {
        $ability = "sirsoft-board.{$slug}.{$suffix}";
        $user = $request->user();

        if (PermissionHelper::check($ability, $user)) {
            return;
        }

        $params = ['required_permissions' => $ability];

        throw new HttpResponseException(
            $user === null
                ? ResponseHelper::unauthorized('auth.guest_permission_denied', $params)
                : ResponseHelper::forbidden('auth.permission_denied', $params)
        );
    }
}
