@include('errors.page', [
    'code' => 500,
    'icon' => 'wrench',
    'title' => __('Something went wrong'),
    'message' => __('An unexpected error stopped this page from loading. It has been logged for the technical team. Please try again; if it keeps happening, contact your administrator.'),
])
