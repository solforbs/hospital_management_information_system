@include('errors.page', [
    'code' => 419,
    'icon' => 'clock',
    'title' => __('Your session expired'),
    'message' => __('For your security, the page expired after being open for a while. Refresh the page and try again; nothing was saved from the last attempt.'),
    'primary' => ['url' => url()->previous() ?: url('/'), 'label' => __('Refresh and try again')],
    'back' => false,
])
