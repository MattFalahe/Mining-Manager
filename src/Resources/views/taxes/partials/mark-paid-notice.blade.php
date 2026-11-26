{{-- Inside a modal moved to <body>, so Bootstrap's alert rather than the wrapper-scoped mm-notice. --}}
<div class="alert alert-warning py-2 px-3 small">
    <strong><i class="fas fa-exclamation-triangle"></i> {{ trans('mining-manager::taxes.mark_paid_not_waive_title') }}</strong><br>
    {{ trans('mining-manager::taxes.mark_paid_not_waive') }}
    <div class="mt-1">
        {{ trans('mining-manager::taxes.mark_paid_waive_instead') }}
        <a href="{{ route('mining-manager.help') }}#waiving-a-bill" class="alert-link" target="_blank" rel="noopener">{{ trans('mining-manager::taxes.mark_paid_how_to_waive') }}</a>
    </div>
</div>
