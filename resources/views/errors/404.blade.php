@include('errors.page', [
    'code' => 404,
    'icon' => 'compass',
    'title' => __('Page not found'),
    'message' => __("We couldn't find the page you were looking for. It may have been moved, or the link may be mistyped. If you followed a link to a patient or record, it may belong to another hospital."),
])
