@include('errors.page', [
    'code' => isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400,
    'icon' => 'alert',
    'title' => __('This request could not be completed'),
    'message' => __('Something about the request was not right. Go back and try again.'),
])
