<x-action-section>
    <x-slot name="title">Notifications</x-slot>
    <x-slot name="description">Choisissez les e-mails que vous recevez.</x-slot>

    <x-slot name="content">
        <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" wire:model.live="assignmentEmails" class="mt-0.5 rounded border-gray-300 text-indigo-600" data-testid="assignment-emails">
            <span>
                <span class="font-medium text-gray-900 dark:text-white">Devoirs</span><br>
                Un e-mail quand un responsable de vos organisations publie un devoir, et un rappel la veille de l'échéance s'il n'est pas terminé.
            </span>
        </label>
        @if ($saved)
            <p class="mt-3 text-sm text-emerald-600 dark:text-emerald-400" role="status">{{ $saved }}</p>
        @endif
    </x-slot>
</x-action-section>
