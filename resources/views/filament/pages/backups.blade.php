<x-filament-panels::page>
    @if ($failed = $this->lastFailure())
        <x-filament::callout icon="heroicon-o-exclamation-triangle" color="danger">
            <x-slot name="heading">{{ __('The last backup failed (:moment)', ['moment' => $failed]) }}</x-slot>
            <x-slot name="description">{{ __('Make a backup now to see the reason. The backups below are still there.') }}</x-slot>
        </x-filament::callout>
    @endif

    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('A backup is made every night and kept for :days days. Download one now and then and keep it somewhere else: a backup on the same server does not help if the server itself is lost.', ['days' => \App\Support\Backups\Backups::keepDays()]) }}
    </p>

    {{ $this->table }}
</x-filament-panels::page>
