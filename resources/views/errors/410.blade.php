@include('errors.page', [
    'code' => 410,
    'icon' => 'link',
    'title' => __('This link is no longer valid'),
    'message' => __('The link has expired or was already used. Ask for a new one, or log in normally.'),
])
