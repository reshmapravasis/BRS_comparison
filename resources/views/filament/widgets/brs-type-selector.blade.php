<x-filament::widget class="fi-brs-selector-widget">
    <x-filament::card class="relative">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                     <!-- BRS Types -->
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Select the BRS user-type
                </p>
                <br>
            </div>
            {{ $this->form }}
        </div>
        {{-- Optional: Add styling to mimic the original PHP layout --}}
    </x-filament::card>
</x-filament::widget>


