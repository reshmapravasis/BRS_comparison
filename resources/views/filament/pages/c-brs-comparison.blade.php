<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 🔹 Upload Form --}}
        <x-filament::section>
            {{ $this->form }}
            <div class="mt-4 flex justify-end">
                <x-filament::button color="primary" wire:click="submitComparison" icon="heroicon-o-arrow-path">
                    Run Comparison
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- 🔹 Results --}}
        @if($this->ledgerCount > 0 || $this->apiCount > 0)
            {{-- ✅ Summary --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mt-4 bg-gray-50 dark:bg-gray-800 p-4 rounded-xl shadow-sm space-y-2 sm:space-y-0">
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <strong>Total Entry in Bank Statement:</strong> {{ $this->ledgerCount }} |
                    <strong>Total Entry within Date Range:</strong> {{ $this->apiCount }}
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <strong>Total Compared:</strong> {{ $this->matchedCount + $this->unmatchedCount }} |
                    <span class="text-green-600 dark:text-green-400">Matched: {{ $this->matchedCount }}</span> |
                    <span class="text-red-600 dark:text-red-400">Unmatched: {{ $this->unmatchedCount }}</span>
                </div>
            </div>

            {{-- ✅ Toggle Buttons --}}
        <x-filament::section>
            <div class="flex space-x-4 mt-4">
                <x-filament::button
                    color="{{ $this->viewMode === 'matched' ? 'success' : 'gray' }}"
                    wire:click="toggleView('matched')"
                    :disabled="$this->matchedCount === 0"
                >
                    Matched ({{ $this->matchedCount }})
                </x-filament::button>

                <x-filament::button
                    color="{{ $this->viewMode === 'unmatched' ? 'danger' : 'gray' }}"
                    wire:click="toggleView('unmatched')"
                    :disabled="$this->unmatchedCount === 0"
                >
                    Unmatched ({{ $this->unmatchedCount }})
                </x-filament::button>
            </div>

            {{-- ✅ Table --}}
            <div class="mt-6">
    {{-- 🎯 CRITICAL FIX: Add a wire:key tied to the $viewMode or the $tableRefreshKey --}}
    {{-- We'll use the unique key we defined in the PHP class --}}
    <div wire:key="table-{{ $this->tableRefreshKey }}">
        {{ $this->table }}
    </div>
</div>
        </x-filament-panels::page>  
        @endif
    </div>
</x-filament-panels::page>
