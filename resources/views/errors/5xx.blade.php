@include('errors.page', [
    'code' => isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500,
    'icon' => 'wrench',
    'title' => __('Something went wrong'),
    'message' => __('The system could not complete this request. Please try again in a moment; if it keeps happening, contact your administrator.'),
])
