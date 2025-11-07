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

            [$matched, $unmatchedLedger, $unmatchedBank] =
                $this->compareTransactions($ledgerData, $bankData);

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

    $unmatchedBankArray = $bankData->toArray();
    $bankIndicesUsed = [];

    // Helpers
    $normalizeNarration = function ($text) {
        $text = (string) $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9]/i', '', $text);
        return $text;
    };

    $normalizeAmount = function ($val) {
        $v = (string) $val;
        $v = str_replace([',', '(', ')', ' '], '', $v);
        return number_format((float) $v, 2, '.', '');
    };

    $normalizeDate = function ($d) {
        $d = trim((string) $d);
        if ($d === '') return null;
        $ts = strtotime($d);
        return $ts ? date('Y-m-d', $ts) : null;
    };

    $isDateClose = function ($d1, $d2) {
        if (!$d1 || !$d2) return true; // if one missing, ignore date
        $diff = abs(strtotime($d1) - strtotime($d2)) / 86400;
        return $diff <= 2; // within ±2 days
    };

    foreach ($ledgerData as $ledgerIndex => $ledgerLine) {
        $foundMatch = false;

        $ledgerAmount = $normalizeAmount($ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0);
        $ledgerNarration = $normalizeNarration($ledgerLine['narration'] ?? '');
        $ledgerDate = $normalizeDate($ledgerLine['date'] ?? null);

        foreach ($unmatchedBankArray as $bankIndex => $bankRec) {
            if (in_array($bankIndex, $bankIndicesUsed, true)) continue;

            $bankAmount = $normalizeAmount($bankRec['amount_abs'] ?? $bankRec['amount'] ?? 0);
            $bankNarration = $normalizeNarration($bankRec['narration'] ?? '');
            $bankDate = $normalizeDate($bankRec['date'] ?? null);

            // --- Amount Check ---
            if ($ledgerAmount !== $bankAmount) continue;

            // --- Narration Similarity ---
            similar_text($ledgerNarration, $bankNarration, $similarity);
            if ($similarity < 75) continue; // require ≥75% similarity

            // --- Date Check (±2 days allowed) ---
            if (!$isDateClose($ledgerDate, $bankDate)) continue;

            // ✅ It's a Match
            $matched[] = [
                'tra_date' => $ledgerLine['date'] ?? null,
                'tra_narration' => $ledgerLine['narration'] ?? '',
                'tra_amount' => $ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0,
                'tra_type' => ($ledgerLine['amount'] ?? 0) > 0 ? 'Credit' : 'Debit',
                'match_date' => $bankRec['date'] ?? null,
                'match_narration' => $bankRec['narration'] ?? '',
                'match_amount' => $bankRec['amount_abs'] ?? $bankRec['amount'] ?? 0,
                'match_type' => ($bankRec['amount'] ?? 0) > 0 ? 'Credit (Bank)' : 'Debit (Bank)',
                'similarity' => round($similarity, 2),
                'match_method' => 'Amount + Narration (≥75%)',
            ];

            $bankIndicesUsed[] = $bankIndex;
            $foundMatch = true;
            break;
        }

        if (!$foundMatch) {
            $unmatchedLedger[] = [
                'date' => $ledgerLine['date'] ?? null,
                'narration' => $ledgerLine['narration'] ?? '',
                'amount' => $ledgerLine['amount_abs'] ?? $ledgerLine['amount'] ?? 0,
                'type' => ($ledgerLine['amount'] ?? 0) > 0 ? 'Credit' : 'Debit',
                'reason' => 'No matching entry found in Bank Statement.',
            ];
        }
    }

    $finalUnmatchedBank = collect($unmatchedBankArray)
        ->filter(fn($val, $key) => !in_array($key, $bankIndicesUsed, true))
        ->values()
        ->toArray();

    return [$matched, $unmatchedLedger, $finalUnmatchedBank];
}



  public function table(Table $table): Table
{
    $data = match ($this->viewMode) {
        'matched' => $this->results['matched'] ?? [],
        'unmatched_ledger' => $this->results['unmatched_ledger'] ?? [],
        'unmatched_bank' => $this->results['unmatched_bank'] ?? [],
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
            TextColumn::make('tra_narration')->label('Narration')->limit(40),
            TextColumn::make('tra_amount')
                ->label('Amount')
                ->getStateUsing(fn($record) => number_format($record['tra_amount'] ?? 0, 2))
                ->alignRight()
                ->badge()
                ->color('warning'),
            TextColumn::make('tra_type')
                ->label('Cr/Dr')
                ->color(fn($record) => ($record['tra_amount'] ?? 0) > 0 ? 'success' : 'danger'),
        ],

        'unmatched_ledger' => [
            $slNoColumn,
            TextColumn::make('date')->label('Date')->sortable(),
            TextColumn::make('narration')->label('Narration')->limit(40),
            TextColumn::make('amount')
                ->label('Amount')
                ->getStateUsing(fn($record) => number_format($record['amount'] ?? 0, 2))
                ->alignRight()
                ->badge()
                ->color('danger'),
            TextColumn::make('type')
                ->label('Cr/Dr')
                ->color(fn($record) => ($record['amount'] ?? 0) > 0 ? 'success' : 'danger'),
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
                    return ($record['moved_from_unmatched'] ?? false) ? 'danger' : 'success';
                }
                return 'primary';
            })
            ->button() // ✅ Makes it look like a real button (not a link)
            ->outlined(false) // solid color button
            ->disabled(function ($record) {
                // Disable when "Verified" (not moved_from_unmatched)
                return $this->viewMode === 'matched' && empty($record['moved_from_unmatched']);
            })
            ->action(function ($record) {
                if ($this->viewMode === 'unmatched_ledger') {
                    $this->verifyRow($record);
                } elseif ($this->viewMode === 'matched' && ($record['moved_from_unmatched'] ?? false)) {
                    $this->revertRow($record);
                }
            }),
            
    ];

    return $table
        ->records(fn() => collect($data))
        ->columns($columns)
        ->actions($actions)
        ->paginated([10, 25, 50, 100])
        ->defaultSort(match ($this->viewMode) {
            'matched' => 'tra_date',
            default => 'date',
        }, 'desc')
        ->heading(Str::headline($this->viewMode) . " Entries (" . count($data) . ")");
}

public function verifyRow($uid)
{
    $record = collect($this->results['unmatched_ledger'])->firstWhere('uid', $uid);
    if (!$record) return;

    $item = [
        'tra_date' => $record['date'],
        'tra_narration' => $record['narration'],
        'tra_amount' => $record['amount'],
        'tra_type' => $record['type'],
        'uid' => $uid,
        'moved_from_unmatched' => true,
        'original_index' => array_search($uid, array_column($this->results['unmatched_ledger'], 'uid')),
    ];

    $this->results['matched'][] = $item;
    $this->results['unmatched_ledger'] = array_values(array_filter($this->results['unmatched_ledger'], fn($r) => $r['uid'] !== $uid));

    $this->matchedCount = count($this->results['matched']);
    $this->unmatchedCount = count($this->results['unmatched_ledger']);
}

public function revertRow($uid)
{
    $record = collect($this->results['matched'])->firstWhere('uid', $uid);
    if (!$record) return;

    $item = [
        'date' => $record['tra_date'],
        'narration' => $record['tra_narration'],
        'amount' => $record['tra_amount'],
        'type' => $record['tra_type'],
        'uid' => $uid,
    ];

    $this->results['matched'] = array_values(array_filter($this->results['matched'], fn($r) => $r['uid'] !== $uid));
    $this->results['unmatched_ledger'][] = $item;

    $this->matchedCount = count($this->results['matched']);
    $this->unmatchedCount = count($this->results['unmatched_ledger']);
}





}
