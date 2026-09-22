<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 무작위 목록의 **연타 방지** — 쿨다운 동안은 직전에 뽑은 것을 그대로 돌려준다.
 *
 * ## 왜 429 가 아닌가
 *
 * 목록 화면(`board/index`)의 데이터소스는 401·403 만 따로 다루고 나머지 오류는
 * `hasError` 로 넘긴다 — 그러면 목록 대신 "오류가 발생했습니다 + 새로고침" 화면이 뜬다.
 * "다시 뽑기" 를 두 번 눌렀다고 화면이 사라지면 안 되므로, 제한은 **오류 없이** 건다.
 * 봇 SSR 도 429 를 받으면 빈 목록(`fallback`)을 색인할 수 있다.
 *
 * ## 캐시 항목 하나가 두 가지를 나타낸다
 *
 * 항목이 살아 있으면 "쿨다운 중" 이고, 그 값이 곧 "직전 결과" 다. 둘을 따로 두면 같은
 * 사실을 두 곳에 적게 된다. 저장소는 코어 기본 캐시({@see Cache::store()})를 그대로 쓴다.
 *
 * **되쓰는 id 도 노출 판정을 다시 받는다** — 그 3초 사이에 글이 지워지거나 권한이 바뀔 수
 * 있다. 판정은 호출부({@see DocListRenderer})가 한다.
 *
 * 캐시가 없거나 실패해도 목록은 나와야 하므로, 읽기·쓰기 모두 실패를 삼키고 새로 뽑는다.
 */
final class RandomDrawCache
{
    /** 쿨다운(초). 이 시간 안의 재요청에는 직전 결과를 준다 */
    public const COOLDOWN_SECONDS = 3;

    /** 캐시 키 앞자리 */
    private const PREFIX = 'g7lw:rand:';

    /**
     * 쿨다운 안이면 직전 결과를, 아니면 새로 뽑아 저장하고 그것을 돌려준다.
     *
     * @param  int  $count  이번에 뽑을 개수 — 캐시 키에 들어간다
     * @param  callable(): list<int>  $draw  새로 뽑는 방법
     * @return list<int>
     */
    public static function remember(int $boardId, Request $request, int $count, callable $draw): array
    {
        $user = $request->user();

        $key = self::key(
            $boardId,
            $user === null ? null : (int) $user->getAuthIdentifier(),
            $request->ip(),
            $count
        );

        try {
            $reused = self::reuse(Cache::get($key));
        } catch (\Throwable) {
            // 캐시를 못 읽어도 목록은 나와야 한다. 쿨다운만 없는 셈이다.
            $reused = null;
        }

        if ($reused !== null) {
            return $reused;
        }

        $ids = $draw();

        try {
            Cache::put($key, $ids, self::COOLDOWN_SECONDS);
        } catch (\Throwable) {
            // 저장에 실패하면 다음 요청이 다시 뽑는다. 그뿐이다.
        }

        return $ids;
    }

    /**
     * 캐시 키 — 게시판별·**뽑는 개수별**·요청자별로 갈린다.
     *
     * 비회원은 IP 로 가르되 **원문을 키에 남기지 않는다**(해시). 캐시 키는 운영 중에 눈에
     * 띄는 자리라 방문자 주소를 적어 둘 이유가 없다.
     *
     * **개수가 키에 들어가는 이유**: 뽑는 개수는 그 요청의 한 쪽 개수를 따르므로 PC(20)와
     * 모바일(15)이 다르다. 개수를 키에서 빼면 PC 로 뽑은 직후 3초 안에 연 모바일 화면이
     * 20건짜리 직전 결과를 되써서, 한 쪽에 안 들어가는 5건 때문에 뜻 없는 2쪽 페이저가
     * 다시 생긴다.
     *
     * 봇 SSR 의 내부 호출은 전부 서버 자신의 주소로 모이므로 봇은 쿨다운 동안 같은 목록을
     * 본다 — 봇에게 보이는 무작위가 고정되는 것은 이미 받아들인 전제다.
     */
    public static function key(int $boardId, ?int $userId, ?string $ip, int $count): string
    {
        $identity = $userId !== null && $userId > 0
            ? 'u'.$userId
            : 'ip'.sha1((string) $ip);

        return self::PREFIX.$boardId.':n'.$count.':'.$identity;
    }

    /**
     * 캐시에서 읽은 값이 되쓸 수 있는 직전 결과인지 판정한다.
     *
     * 항목이 없으면(`null`) 쿨다운이 풀린 것이다 — 그때만 새로 뽑는다.
     * **빈 배열도 직전 결과다**(후보가 없는 게시판). 배열이 아닌 것은 남이 같은 키를 쓴
     * 것이거나 깨진 값이므로 버린다.
     *
     * @return list<int>|null  되쓸 id 목록, 새로 뽑아야 하면 null
     */
    public static function reuse(mixed $cached): ?array
    {
        if (! is_array($cached)) {
            return null;
        }

        return array_values(array_filter(
            array_map(static fn ($id): int => is_scalar($id) ? (int) $id : 0, $cached),
            static fn (int $id): bool => $id > 0
        ));
    }
}
