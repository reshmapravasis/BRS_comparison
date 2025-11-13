<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Filament\Forms\Form;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Symfony\Component\HttpFoundation\StreamedResponse;


class IBrsComparison extends Page implements HasForms, HasTable
{
        use InteractsWithForms;
        use InteractsWithTable;
        use WithFileUploads;

        protected static ?string $title = 'BRS Comparison: i-Bank';
        protected  string $view = 'filament.pages.i-brs-comparison';

        public $data = []; // ✅ required for Filament form state

        public $bank_file = [];
        public $ledger_file = [];
        public int $totalBankEntries = 0;
        public int $totalLedgerEntries = 0;
        public int $matchedCount = 0;
        public int $unmatchedCount = 0;

        public array $results = [
            'matched' => [],
            'unmatched_ledger' => [], // Clearer name
            'unmatched_bank' => [],
            'duplicates' => [],
        ];

        public string $viewMode = 'matched'; // matched, unmatched, unmatched_bank
        public function toggleView(string $mode): void
        {
            $this->viewMode = $mode;
            $this->resetTable();
        }
        public function mount(): void
        {
            if (session()->has('brs_results')) {
                $this->results = session('brs_results');
                $this->matchedCount = count($this->results['matched'] ?? []);
                $this->unmatchedCount = count($this->results['unmatched_ledger'] ?? []);
            }
        }


        public static function getNavigationIcon(): ?string
        {
            return 'heroicon-o-banknotes';
        }

        public static function shouldRegisterNavigation(): bool
        {
            $user = Auth::user();
            if ($user && $user->user_type == 1) {
                return true;
            }
            return $user->brs_type === 'xls&ibank';
        }

        protected function getFormSchema(): array
        {
            return [
                FileUpload::make('ledger_file')
                    ->label('Upload Your Ledger Statement (.xls, .xlsx, .qif)')
                    ->required()
                    ->directory('livewire-tmp')
                    ->preserveFilenames()
                    ->columnSpanFull()
                    ->multiple(false)
                    ->rules([
                        'required',
                        'file',
                        'max:10240', // 10MB
                    ])
                    ->live(),

                FileUpload::make('bank_file')
                    ->label('Upload Our Bank Statement (.xls, .xlsx, .qif)')
                    ->required()
                    ->directory('livewire-tmp')
                    ->preserveFilenames()
                    ->columnSpanFull()
                    ->multiple(false)
                    ->rules([
                        'required',
                        'file',
                        'max:10240', // 10MB
                    ])
                    ->live(),
            ];
        }

        

        public function compare(): void
        {
            $data = $this->form->getState();

            if (empty($data['bank_file']) || empty($data['ledger_file'])) {
                Notification::make()
                    ->title('⚠️ Missing Files')
                    ->danger()
                    ->send();
                return;
            }

            try {
                $ledgerPath = Storage::disk('local')->path($data['ledger_file']);
                $bankPath = Storage::disk('local')->path($data['bank_file']);

                $ledgerData = $this->readFile($ledgerPath, 'ledger');
                $bankData = $this->readFile($bankPath, 'bank');

                [$matched, $unmatchedLedger, $unmatchedBank, $duplicates] = $this->compareTransactions($ledgerData, $bankData);
    


                // ✅ Add unique UID + movement tracking to every row
                $addUid = function ($rows, $isMatched = false) {
                    return collect($rows)->values()->map(function ($row, $i) use ($isMatched) {
                        return array_merge($row, [
                            'uid' => (string) \Illuminate\Support\Str::uuid(),
                            'sl_no' => $i + 1,
                            'moved_from_unmatched' => false,
                            ]);
                        })->toArray();
                };

                $this->results['matched'] = $addUid($matched, true);
                $this->results['unmatched_ledger'] = $addUid($unmatchedLedger, false);
                $this->results['unmatched_bank'] = $addUid($unmatchedBank, false);
                $this->results['duplicates'] = $addUid($duplicates, false);
                $this->totalLedgerEntries = is_countable($ledgerData) ? $ledgerData->count() : count($ledgerData);
                $this->totalBankEntries   = is_countable($bankData) ? $bankData->count()   : count($bankData);
                $this->matchedCount = count($matched);
                $this->unmatchedCount = count($unmatchedLedger);
                session()->put('brs_results', $this->results);
                Storage::disk('local')->delete($data['ledger_file']);
                Storage::disk('local')->delete($data['bank_file']);

                $this->ledger_file = null;
                $this->bank_file = null;
                $this->form->fill();

                $this->resetTable();

                Notification::make()
                    ->title('✅ Comparison Completed')
                    ->body("Matched: {$this->matchedCount} | Unmatched: {$this->unmatchedCount}")
                    ->success()
                    ->send();

            } catch (\Exception $e) {
                Notification::make()
                    ->title('❌ Comparison Failed')
                    ->body('Error: ' . $e->getMessage())
                    ->danger()
                    ->send();
            }
        }

        protected function readFile(string $filePath, string $type): Collection
        {
            $fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if (in_array($fileExt, ['xls', 'xlsx'])) {
                return $this->convertxls($filePath, $type);
            }

            throw new \Exception("Invalid file type: .$fileExt. Only .xls, .xlsx, or .qif are allowed.");
        }

        protected function convertxls(string $filePath, string $type): Collection
        {
            $dateCol = $type === 'ledger' ? 'C' : 'A';
            $narrationCol = $type === 'ledger' ? 'G' : 'B';
            $debitCol = $type === 'ledger' ? 'H' : 'G';
            $creditCol = $type === 'ledger' ? 'I' : 'J';
            $filterCol = $type === 'ledger' ? 'J' : 'M';

            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = collect($sheet->toArray(null, true, true, true));
            $rows->shift();

            $data = $rows->filter(fn($row) => isset($row[$filterCol]))->map(function ($row) use ($dateCol, $narrationCol, $debitCol, $creditCol, $type) {
                $amount = 0.00;
                $narration = trim($row[$narrationCol] ?? '');

                if (!empty(trim($row[$debitCol] ?? '')) && ($row[$debitCol] != '0')) {
                    $amount = -(float) str_replace(',', '', trim($row[$debitCol]));
                } elseif (!empty(trim($row[$creditCol] ?? '')) && ($row[$creditCol] != '0')) {
                    $amount = (float) str_replace(',', '', trim($row[$creditCol]));
                }

                if ($type === 'bank') {
                    if (stripos($narration, 'to ') === 0) {
                        $narration = trim(substr($narration, 3));
                    } elseif (stripos($narration, 'by ') === 0) {
                        $narration = trim(substr($narration, 3));
                    }
                }

                return [
                    'date' => $row[$dateCol],
                    'narration' => $narration,
                    'amount' => $amount,
                    'amount_abs' => abs($amount),
                ];
            });

            if ($data->isEmpty()) {
                throw new \Exception("No transaction data found in the $type file.");
            }

            return $data;
        }

    protected function compareTransactions(Collection $ledgerData, Collection $bankData): array
{
    $matched = [];
    $unmatchedLedger = [];
    $duplicates = []; // ✅ new
    $unmatchedBankArray = $bankData->toArray();
    $bankIndicesUsed = [];

    $normalizeNarration = fn($t) => preg_replace('/[^a-z0-9]/i', '', strtolower((string) $t));
    $normalizeAmount = fn($v) => number_format((float) str_replace([',', '(', ')', ' '], '', (string)$v), 2, '.', '');
    $normalizeDate = fn($d) => ($ts = strtotime(trim((string)$d))) ? date('Y-m-d', $ts) : null;
    $isDateClose = fn($d1, $d2) => !$d1 || !$d2 || abs(strtotime($d1) - strtotime($d2)) / 86400 <= 2;

    foreach ($ledgerData as $ledgerIndex => $ledgerLine) {
        $foundMatch = false;
        $ledgerAmount = $normalizeAmount($ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0);
        $ledgerNarration = $normalizeNarration($ledgerLine['narration'] ?? '');
        $ledgerDate = $normalizeDate($ledgerLine['date'] ?? null);

        $possibleMatches = [];
        foreach ($unmatchedBankArray as $bankIndex => $bankRec) {
            $bankAmount = $normalizeAmount($bankRec['amount_abs'] ?? $bankRec['amount'] ?? 0);
            if ($ledgerAmount !== $bankAmount) continue;

            $bankNarration = $normalizeNarration($bankRec['narration'] ?? '');
            similar_text($ledgerNarration, $bankNarration, $similarity);
            if ($similarity < 75) continue;

            $bankDate = $normalizeDate($bankRec['date'] ?? null);
            if (!$isDateClose($ledgerDate, $bankDate)) continue;

            $possibleMatches[] = [
                'index' => $bankIndex,
                'record' => $bankRec,
                'similarity' => $similarity,
            ];
        }

        if (count($possibleMatches) > 0) {
            // sort best first
            usort($possibleMatches, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

            $best = $possibleMatches[0];
            $bankKey = $normalizeNarration($best['record']['narration']).'|'.$normalizeAmount($best['record']['amount']).'|'.$normalizeDate($best['record']['date']);

            // ✅ Check if this bank transaction was already used before (duplicate)
            $alreadyUsed = collect($matched)->first(fn($m) =>
                $normalizeNarration($m['match_narration']) === $normalizeNarration($best['record']['narration'])
                && $normalizeAmount($m['match_amount']) === $normalizeAmount($best['record']['amount'])
                && $normalizeDate($m['match_date']) === $normalizeDate($best['record']['date'])
            );

            if ($alreadyUsed) {
                // 🟡 Move to duplicates
                $duplicates[] = [
                    'tra_date' => $ledgerLine['date'] ?? null,
                    'tra_narration' => $ledgerLine['narration'] ?? '',
                    'tra_amount' => $ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0,
                    'tra_type' => ($ledgerLine['amount'] ?? 0) > 0 ? 'Cr' : 'Dr',
                    'dup_date' => $best['record']['date'] ?? null,
                    'dup_narration' => $best['record']['narration'] ?? '',
                    'dup_amount' => $best['record']['amount_abs'] ?? $best['record']['amount'] ?? 0,
                    'dup_type' => ($best['record']['amount'] ?? 0) > 0 ? 'Credit (Bank)' : 'Debit (Bank)',
                    'similarity' => round($best['similarity'], 2),
                    'reason' => 'Duplicate match: same narration, amount & date as existing match',
                ];
            } else {
                $bankIndicesUsed[] = $best['index'];
                $matched[] = [
                    'tra_date' => $ledgerLine['date'] ?? null,
                    'tra_narration' => $ledgerLine['narration'] ?? '',
                    'tra_amount' => $ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0,
                    'tra_type' => ($ledgerLine['amount'] ?? 0) > 0 ? 'Cr' : 'Dr',
                    'match_date' => $best['record']['date'] ?? null,
                    'match_narration' => $best['record']['narration'] ?? '',
                    'match_amount' => $best['record']['amount_abs'] ?? $best['record']['amount'] ?? 0,
                    'match_type' => ($best['record']['amount'] ?? 0) > 0 ? 'Credit (Bank)' : 'Debit (Bank)',
                    'similarity' => round($best['similarity'], 2),
                    'match_method' => 'Amount + Narration',
                ];
            }
        } else {
            $unmatchedLedger[] = [
                'date' => $ledgerLine['date'] ?? null,
                'narration' => $ledgerLine['narration'] ?? '',
                'amount' => $ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0,
                'type' => ($ledgerLine['amount'] ?? 0) > 0 ? 'Cr' : 'Dr',
                'reason' => 'No matching entry found in Bank Statement.',
            ];
        }
    }

    $finalUnmatchedBank = collect($unmatchedBankArray)
        ->filter(fn($val, $key) => !in_array($key, $bankIndicesUsed, true))
        ->values()
        ->toArray();

    return [$matched, $unmatchedLedger, $finalUnmatchedBank, $duplicates];
}


    public function downloadManualBackup(): ?StreamedResponse
    {
        $manualData = collect($this->results['matched'] ?? [])
            ->filter(fn($row) => ($row['moved_from_unmatched'] ?? false))
            ->values()
            ->toArray();

        if (empty($manualData)) {
            Notification::make()
                ->title('⚠️ No Manually Verified Data')
                ->body('There are no manually matched entries to back up.')
                ->warning()
                ->send();

            return response()->stream(fn() => '', 200, ['Content-Type' => 'text/plain']);
        }

        $fileName = 'manual_matched_backup_' . date('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$fileName\"",
        ];

        $callback = function () use ($manualData) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Date', 'Narration', 'Amount', 'Cr/Dr', 'UID']);

            foreach ($manualData as $row) {
                fputcsv($file, [
                    $row['tra_date'] ?? '',
                    $row['tra_narration'] ?? '',
                    $row['tra_amount'] ?? '',
                    $row['tra_type'] ?? '',
                    // $row['uid'] ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function table(Table $table): Table
    {
        $data = match ($this->viewMode) {
            'matched' => $this->results['matched'] ?? [],
            'unmatched_ledger' => $this->results['unmatched_ledger'] ?? [],
            'unmatched_bank' => $this->results['unmatched_bank'] ?? [],
            'duplicates' => $this->results['duplicates'] ?? [],
            default => [],
        };

        $slNoColumn = TextColumn::make('sl_no')
            ->label('Sl No')
            ->getStateUsing(fn($record, $rowLoop) => $rowLoop->index + 1)
            ->sortable();

        $columns = match ($this->viewMode) {
            'matched' => [
                $slNoColumn,
                TextColumn::make('tra_date')->label('Date')->sortable(),
                TextColumn::make('tra_narration')->label('Narration')
                    // ->formatStateUsing(function ($state) {
                    //     if (!$state) return '';
                        
                    //     // Replace separators with newlines for better readability
                    //     $formatted = str_replace(['-', '/'], ["-\n", "/\n"], $state);
                        
                    //     // Keep safe HTML line breaks
                    //     return nl2br(e($formatted));
                    // })
                    // ->html()
                    // ->wrap()
                    // ->extraAttributes([
                    //     'style' => 'white-space: normal; line-height: 1.4; font-family: monospace; font-size: 13px;',
                    // ]),
                    ->wrap()->extraAttributes(['style' => 'white-space: normal; max-width: none;']),
                TextColumn::make('tra_amount')
                    ->label('Amount')
                    ->getStateUsing(fn($record) => number_format($record['tra_amount'] ?? 0, 2))
                    ->alignRight(),
                    //->badge()
                    //->color('warning'),
                TextColumn::make('tra_type')
                    ->label('Cr/Dr')
                    ->color(fn($record) => match(strtolower($record['tra_type'] ?? '')) {
                        'cr' => 'success',
                        'dr' => 'danger',
                        default => 'secondary',
                    })
            ],

            'unmatched_ledger' => [
                $slNoColumn,
                TextColumn::make('date')->label('Date')->sortable(),
                TextColumn::make('narration')->label('Narration')->wrap()->extraAttributes(['style' => 'white-space: normal; max-width: none;']),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->getStateUsing(fn($record) => number_format($record['amount'] ?? 0, 2))
                    ->alignRight(),
                    //->badge()
                    //->color('danger'),
                TextColumn::make('type')
                    ->label('Cr/Dr')
                    ->color(fn($record) => match(strtolower($record['type'] ?? '')) {
                        'cr' => 'success',
                        'dr' => 'danger',
                        default => 'secondary',
                    })
            ],

            'duplicates' => [
                $slNoColumn,
                TextColumn::make('tra_date')->label('Date')->sortable(),
                TextColumn::make('tra_narration')->label('Narration')->wrap(),
                TextColumn::make('tra_amount')->label('Amount')->getStateUsing(fn($r) => number_format($r['tra_amount'] ?? 0, 2))->alignRight(),
                TextColumn::make('dup_date')->label('Duplicate Date'),
                TextColumn::make('dup_narration')->label('Duplicate Narration')->wrap(),
                TextColumn::make('dup_amount')->label('Duplicate Amount')->getStateUsing(fn($r) => number_format($r['dup_amount'] ?? 0, 2))->alignRight(),
                TextColumn::make('reason')->label('Reason')->wrap(),
            ],

            default => [],
        };
        
        // ✅ Add row actions instead of HTML buttons
        
        $actions = [
            Action::make('verify')
                ->label(function ($record) {
                    return $this->viewMode === 'matched'
                        ? (($record['moved_from_unmatched'] ?? false) ? 'Revert' : 'Verified')
                        : 'Verify';
                })
                ->color(function ($record) {
                    if ($this->viewMode === 'matched') {
                        return ($record['moved_from_unmatched'] ?? false) ? 'danger' : 'info';
                    }
                    return 'primary';
                })
                ->button()
                ->outlined(false)
                ->disabled(function ($record) {
                    // Disable when "Verified" (not moved_from_unmatched)
                    return $this->viewMode === 'matched' && empty($record['moved_from_unmatched']);
                })
                ->action(function ($record) {
                    // IMPORTANT: pass the uid string (not the whole record)
                    $uid = $record['uid'] ?? null;
                    if (!$uid) return;

                    if ($this->viewMode === 'unmatched_ledger') {
                        $this->verifyRow($uid);
                    } elseif ($this->viewMode === 'matched' && ($record['moved_from_unmatched'] ?? false)) {
                        $this->revertRow($uid);
                    }

                    // persist and refresh table
                    session()->put('brs_results', $this->results);
                    $this->resetTable();
                }),
        ];


        return $table
            ->heading(match ($this->viewMode) {
                'matched' => 'Matched Transactions',
                'unmatched_ledger' => 'Unmatched Transactions',
                'unmatched_bank' => 'Unmatched Bank Transactions',
                default => 'Comparison Results',
            })

            ->records(fn() => collect($data))
            ->columns($columns)
            ->actions($actions)
            ->headerActions([
                    // 1️⃣ Export Matched CSV
                    Action::make('export_matched')
                        ->label('Export Matched')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('success')
                        ->button()
                        ->visible(fn () => $this->viewMode === 'matched')
                        ->action(fn () => $this->exportCurrentViewToCsv('matched')),

                    // 2️⃣ Export Unmatched CSV (Ledger)
                    Action::make('export_unmatched_ledger')
                        ->label('Export Unmatched')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('success')
                        ->button()
                        ->visible(fn () => $this->viewMode === 'unmatched_ledger')
                        ->action(fn () => $this->exportCurrentViewToCsv('unmatched_ledger')),

                    // 3️⃣ Export Unmatched Bank CSV
                    Action::make('export_unmatched_bank')
                        ->label('Export Unmatched Bank CSV')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('info')
                        ->button()
                        ->visible(fn () => $this->viewMode === 'unmatched_bank')
                        ->action(fn () => $this->exportCurrentViewToCsv('unmatched_bank')),

                    // 4️⃣ Download Manual Backup
                    Action::make('download_manual_backup')
                        ->label('Download Backup')
                        ->icon('heroicon-o-cloud-arrow-down')
                        ->color('warning')
                        ->button()
                        ->visible(fn () => $this->viewMode === 'matched')
                        ->action(fn () => $this->downloadManualBackup()),

                    // 5️⃣ Upload Backup (opens modal)
                    Action::make('upload_backup')
                        ->label('Upload Backup')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('info')
                        ->button()
                        ->visible(fn () => $this->viewMode === 'matched')
                        ->form([
                            FileUpload::make('backup_file')
                                ->label('Upload Backup File')
                                ->required()
                                ->storeFiles(false)
                                ->preserveFilenames()
                                ->acceptedFileTypes(['text/csv'])
                                ->live(),
                        ])
                        ->modalSubmitActionLabel('Upload')
                        ->action(function (array $data) {
                            $fileObject = $data['backup_file'] ?? null;

                            if (!$fileObject || !method_exists($fileObject, 'getRealPath')) {
                                Notification::make()
                                    ->title('❌ Upload Failed')
                                    ->body('Please select a valid backup file to upload.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $filePath = $fileObject->getRealPath();
                            if (!$filePath || !file_exists($filePath)) {
                                Notification::make()
                                    ->title('⚠️ File missing or unreadable.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $csvData = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                            if (count($csvData) <= 1) {
                                Notification::make()
                                    ->title('⚠️ Backup file is empty.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $this->backup_file = $fileObject;
                            $this->uploadBackup();
                        }),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultSort(match ($this->viewMode) {
                'matched' => 'tra_date',
                default => 'date',
            }, 'desc');
           // ->heading(Str::headline($this->viewMode) . " Entries (" . count($data) . ")");

    }

    // --- replace verifyRow(...) with this ---
    public function verifyRow($uid)
    {
        // $uid should be a string
        $record = collect($this->results['unmatched_ledger'] ?? [])->firstWhere('uid', $uid);
        if (!$record) return;

        $originalIndex = null;
        // find original index in unmatched_ledger
        foreach ($this->results['unmatched_ledger'] as $i => $r) {
            if (($r['uid'] ?? null) === $uid) {
                $originalIndex = $i;
                break;
            }
        }

        $item = [
            'tra_date' => $record['date'],
            'tra_narration' => $record['narration'],
            'tra_amount' => $record['amount'],
            'tra_type' => $record['type'],
            'uid' => $uid,
            'moved_from_unmatched' => true,
            'original_index' => $originalIndex,
        ];

        // add to matched and remove from unmatched_ledger
        $this->results['matched'][] = $item;
        $this->results['unmatched_ledger'] = array_values(array_filter(
            $this->results['unmatched_ledger'],
            fn($r) => ($r['uid'] ?? null) !== $uid
        ));

        $this->matchedCount = count($this->results['matched']);
        $this->unmatchedCount = count($this->results['unmatched_ledger']);

        // persist and refresh
        session()->put('brs_results', $this->results);
        $this->resetTable();
    }


    // --- replace revertRow(...) with this ---
    public function revertRow($uid)
    {
        $record = collect($this->results['matched'] ?? [])->firstWhere('uid', $uid);
        if (!$record) return;

        $item = [
            'date' => $record['tra_date'],
            'narration' => $record['tra_narration'],
            'amount' => $record['tra_amount'],
            'type' => $record['tra_type'],
            'uid' => $uid,
        ];

        // remove from matched
        $this->results['matched'] = array_values(array_filter(
            $this->results['matched'],
            fn($r) => ($r['uid'] ?? null) !== $uid
        ));

        // restore into unmatched_ledger at original index if available,
        // otherwise push at the end
        $inserted = false;
        $origIndex = $record['original_index'] ?? null;
        if (is_int($origIndex) && $origIndex >= 0) {
            $before = array_slice($this->results['unmatched_ledger'], 0, $origIndex);
            $after  = array_slice($this->results['unmatched_ledger'], $origIndex);
            $this->results['unmatched_ledger'] = array_values(array_merge($before, [$item], $after));
            $inserted = true;
        }

        if (! $inserted) {
            $this->results['unmatched_ledger'][] = $item;
        }

        $this->matchedCount = count($this->results['matched']);
        $this->unmatchedCount = count($this->results['unmatched_ledger']);

        // persist and refresh
        session()->put('brs_results', $this->results);
        $this->resetTable();
    }
}
