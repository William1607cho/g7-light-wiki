<?php

namespace Plugins\G7\Light\Wiki\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 위키 문서 색인 한 줄.
 *
 * @property int $id
 * @property int $board_id
 * @property int $post_id
 * @property string $title
 * @property string $title_norm
 * @property \Illuminate\Support\Carbon|null $edited_at
 */
class WikiDoc extends Model
{
    protected $table = 'light_wiki_docs';

    protected $fillable = [
        'board_id', 'post_id', 'title', 'title_norm', 'edited_at',
    ];

    protected $casts = [
        'board_id' => 'integer',
        'post_id' => 'integer',
        'edited_at' => 'datetime',
    ];
}
