<?php

return [
    /*
    | Named routes that search engines may index and that appear in the
    | sitemap. Everything else (all signed-in pages, invitation and password
    | reset links, APIs) is sent with "noindex" and disallowed in robots.txt.
    | Add a route here only if it is genuinely public and holds no patient data.
    */
    'indexable_routes' => ['login'],
];
