<?php

return [
    'title' => [
        'duplicate' => 'A document with this title already exists on this board. On a wiki board the post title is the document name, so titles cannot repeat.',
    ],
    'board' => [
        'not_wiki' => 'Not a wiki board.',
    ],
    'front' => [
        'random' => 'Random document',
        'other' => 'Other',
        'empty' => 'No documents yet.',
        'tour_recent' => 'Recently edited',
        'tour_random' => 'Random documents',
    ],
    'doc' => [
        'categories' => 'Categories',
        'category_members' => 'Documents in This Category',
        'aliases' => 'Also Known As',
        'backlinks' => 'Documents Linking Here',
        'more' => 'and :count more',
        'empty' => 'No documents yet.',
        'timeline_empty' => 'No events yet.',
    ],
    'settings' => [
        'saved' => 'Wiki board settings saved.',
        'save_failed' => 'Could not save the wiki board settings.',
        'invalid' => 'The wiki board settings are not valid.',
        'board_missing' => 'Board :id was not found.',
        'front_post_mismatch' => 'Post :id does not belong to this board.',
    ],
    'setup' => [
        'created' => 'The wiki board was created with its front page, index and syntax help documents.',
        'converted' => 'The board was turned into a wiki with its front page, index and syntax help documents.',
        'released' => 'The wiki was released. The board and its posts remain, and [[link]] markup in documents now shows as plain text. The search engine view may show the previous page for up to two hours.',
        'author_invalid' => 'User :id does not have the administrator role and cannot author the seed documents.',
        'board_missing' => 'Board :id was not found.',
        'already_wiki' => 'This board is already used as a wiki.',
        'not_basic' => 'Only basic boards can be turned into a wiki.',
        'has_posts' => 'A board with posts cannot be turned into a wiki. Trashed posts, replies and notices count as posts.',
        'has_categories' => 'A board with categories cannot be turned into a wiki.',
        'not_managed' => 'Board :id was not set up by this plugin and cannot be released here.',
        'managed_row_removed' => 'Board :id was created on the first tab, "Create a Wiki Board", and cannot be removed here. Use Release on the first tab.',
        'uninstall_blocked' => ':count wiki board(s) are still registered. Release them in the settings before uninstalling.',
    ],
];
