@include('errors.page', [
    'code' => 401,
    'icon' => 'lock',
    'title' => __('Please log in'),
    'message' => __('You need to be logged in to see this page.'),
    'primary' => ['url' => url('/login'), 'label' => __('Go to login')],
])
