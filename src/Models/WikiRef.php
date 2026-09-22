<?php

namespace Plugins\G7\Light\Wiki\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 본문에서 뽑아 둔 표기 한 줄 (링크·분류·별칭·사건).
 *
 * @property int $id
 * @property int $board_id
 * @property int $post_id
 * @property string $kind
 * @property string $target
 * @property string $target_norm
 * @property string|null $sort_key
 * @property string|null $label
 * @property int $seq
 */
class WikiRef extends Model
{
    /** 문서 링크 `[[문서명]]` */
    public const KIND_LINK = 'link';

    /** 분류 `[[분류:이름]]` */
    public const KIND_CATEGORY = 'category';

    /** 별칭 `[[별칭:이름]]` */
    public const KIND_ALIAS = 'alias';

    /** 사건 `[[연표:키|설명]]` */
    public const KIND_EVENT = 'event';

    protected $table = 'light_wiki_refs';

    protected $fillable = [
        'board_id', 'post_id', 'kind', 'target', 'target_norm', 'sort_key', 'label', 'seq',
    ];

    protected $casts = [
        'board_id' => 'integer',
        'post_id' => 'integer',
        'seq' => 'integer',
    ];
}
