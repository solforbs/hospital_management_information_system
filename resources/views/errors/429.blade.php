@include('errors.page', [
    'code' => 429,
    'icon' => 'gauge',
    'title' => __('Too many attempts'),
    'message' => __('There were too many requests in a short time. Please wait a minute, then try again.'),
])
