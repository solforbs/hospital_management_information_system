@include('errors.page', [
    'code' => 503,
    'icon' => 'tools',
    'title' => __("We'll be right back"),
    'message' => __('The system is being updated and will be available again in a moment. Your data is safe. This page will refresh automatically.'),
    'primary' => ['url' => url()->current(), 'label' => __('Try again')],
    'refresh' => 20,
    'back' => false,
])
