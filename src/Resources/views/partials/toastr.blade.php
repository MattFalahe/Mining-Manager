{{--
    Toastr assets.

    Every interactive page in this plugin reports success and failure through
    toastr, but SeAT's layout does not ship it and neither did we, so the
    global was undefined on every page. In an AJAX success handler that means
    the very first line throws, location.reload() never runs, and a button
    that worked perfectly well looks completely dead. Include this anywhere a
    view calls toastr.

    Loaded after jQuery because SeAT puts its script tags above @stack('javascript').
--}}
@push('head')
<link rel="stylesheet" href="{{ asset('vendor/mining-manager/css/vendor/toastr.min.css') }}">
@endpush

@push('javascript')
<script src="{{ asset('vendor/mining-manager/js/vendor/toastr.min.js') }}"></script>
<script>
    if (window.toastr) {
        toastr.options = {
            closeButton: true,
            progressBar: true,
            positionClass: 'toast-bottom-right',
            timeOut: 5000,
            extendedTimeOut: 2000,
        };
    }
</script>
@endpush
