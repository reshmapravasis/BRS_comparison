<x-filament-panels::page>
    <div class="space-y-6">
        {{-- 🔹 Upload Section --}}
        <x-filament::section>
            {{ $this->form }}

            <div class="mt-4 flex justify-end">
                <x-filament::button color="primary" wire:click="compare" icon="heroicon-o-arrow-path">
                    Run Comparison
                </x-filament::button>
            </div>
        </x-filament::section>

        {{-- 🔹 Results Section --}}
        @if($this->totalLedgerEntries > 0 || $this->totalBankEntries > 0)
            {{-- ✅ Summary --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mt-4 bg-gray-50 dark:bg-gray-800 p-4 rounded-xl shadow-sm space-y-2 sm:space-y-0">
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <strong><span style="color: blue;">Total Entry in Bank Statement:</span></strong><strong><span style="color: gray;"> {{ $this->totalLedgerEntries }} </span></strong>|
                    <strong><span style="color: blue;">Entries in Ledger:</span></strong><strong><span style="color: gray;"> {{ $this->totalBankEntries }} </span></strong>
                </div>
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <strong><span style="color: blue;">Total Compared:</strong> {{ $this->matchedCount + $this->unmatchedCount }} |
                    <span style="color: green;">Matched: {{ $this->matchedCount }}</span> |
                    <span style="color: red;">Unmatched: {{ $this->unmatchedCount }}</span>
                </div>
            </div>

            {{-- ✅ Toggle Buttons --}}
            <div class="flex flex-wrap gap-3 mt-4">
                <x-filament::button
                    color="{{ $this->viewMode === 'matched' ? 'success' : 'gray' }}"
                    wire:click="toggleView('matched')"
                    :disabled="empty($this->results['matched'])"
                >
                    Matched ({{ count($this->results['matched']) }})
                </x-filament::button>

                <x-filament::button
                    color="{{ $this->viewMode === 'unmatched_ledger' ? 'danger' : 'gray' }}"
                    wire:click="toggleView('unmatched_ledger')"
                    :disabled="empty($this->results['unmatched_ledger'])"
                >
                    Unmatched Ledger ({{ count($this->results['unmatched_ledger']) }})
                </x-filament::button>

                
            </div>

            {{-- ✅ Table --}}
            <div class="mt-6">
                {{ $this->table }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
