{{-- Whole days left to pay, or how late a bill is. Shared by My Taxes and the
     dashboard banner so the wording is the same everywhere. Whatever colour
     sits around it should come from isOverdue(), the rule the reminders and
     Tax Overview already use. --}}
@php
    $countdownDays = $tax->daysUntilDue();

    if ($countdownDays > 0) {
        $countdownText = trans_choice('mining-manager::taxes.due_in_days', $countdownDays, ['count' => $countdownDays]);
    } elseif ($countdownDays === 0) {
        $countdownText = trans('mining-manager::taxes.due_today');
    } else {
        $countdownText = trans_choice('mining-manager::taxes.days_late', -$countdownDays, ['count' => -$countdownDays]);
    }
@endphp
{{ $countdownText }}
