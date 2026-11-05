@include('errors.page', [
    'code' => 405,
    'icon' => 'alert',
    'title' => __('That action isn\'t available here'),
    'message' => __('The page was opened in a way it does not support. Go back and try again from the menu.'),
])
