@php
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $generic = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.', 'Invalid signature.'], true);
@endphp
@include('errors.page', [
    'code' => 403,
    'icon' => 'lock',
    'title' => __('Access not allowed'),
    'message' => __("You don't have permission to open this page. If you need it for your work, ask your hospital administrator to update your role."),
    'detail' => $generic ? null : $reason,
])
