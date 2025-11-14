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
                    <strong><span style="color: blue;">Total Entry in Bank Statement:</span></strong> <strong><span style="color: gray;">{{ $this->ledgerCount }} </span></strong> |
                    <strong><span style="color: blue;">Total Entry within Date Range:</strong> <span style="color: gray;">{{ $this->apiCount }} </span></strong>
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <strong><span style="color: blue;">Total Compared:</strong><span style="color: gray;"> {{ $this->matchedCount + $this->unmatchedCount + $this->duplicateCount }} </strong></span> |
                    <span style="color: green;">Matched: {{ $this->matchedCount }}</span> |
                    <span style="color: red;">Unmatched: {{ $this->unmatchedCount }}</span> |
                    <span style="color: red;">Duplicte: {{ $this->duplicateCount }}</span>
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

                <x-filament::button
                    color="{{ $this->viewMode === 'duplicates' ? 'warning' : 'gray' }}"
                    wire:click="toggleView('duplicates')"
                    :disabled="empty($this->results['duplicates'])"
                >
                    Duplicates ({{ count($this->results['duplicates'] ?? []) }})
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