{{-- Красная плашка для уведомления о лицензии: штатный цвет Filament даёт только 10% оттенка. --}}
<style>
    .fi-no-notification[wire\:key^="{{ $id }}."] {
        background-color: var(--danger-600);
        --tw-ring-color: var(--danger-700);
    }

    .fi-no-notification[wire\:key^="{{ $id }}."] .fi-no-notification-title,
    .fi-no-notification[wire\:key^="{{ $id }}."] .fi-no-notification-body,
    .fi-no-notification[wire\:key^="{{ $id }}."] .fi-no-notification-icon,
    .fi-no-notification[wire\:key^="{{ $id }}."] .fi-no-notification-close-btn {
        color: var(--color-white);
    }
</style>
