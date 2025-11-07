<?php

namespace App\Filament\Pages;

use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;
use Illuminate\Contracts\View\View;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile; // add this import
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;





class CBrsComparison extends Page implements  HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use WithFileUploads;

    
    protected static ?string $title = 'BRS Comparison: c-Bank';
    protected static ?string $slug = 'c-brs-comparison';
    protected string $view = 'filament.pages.c-brs-comparison';

    // --- Authorization ---
    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        if ($user && $user->user_type == 1) {
            return true;
        }
        return $user && $user->brs_type === 'xlx&cbank';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-building-library';
    }

    // --- State Properties ---

    public  $uploaded_file = [];
    public int $tableRefreshKey = 0;
    public array $pendingBackupRows = [];
    public array $manualMatched = [];
    public $account_number = null;
    public $url = null;
    public $from_date = null;
    public $to_date = null;
    public bool $showMatched = true;
    public $matchedData = null;
    public int $matchedCount = 0;
    public int $unmatchedCount = 0;
    public int $ledgerCount = 0;
    public int $apiCount = 0;
    public array $unmatchedMap = [];
    public $backup_file = null;

    public array $results = [
    'matched' => [],
    'unmatched' => [],
    
    ];
    public string $viewMode = 'matched';
  
   
    public function toggleView($mode)
    {
        $this->viewMode = $mode;
        // 🎯 CRITICAL FIX: Increment the key to force the Blade to re-render the table element
        // This tells Livewire to destroy and rebuild the component, forcing header actions to appear.
        $this->tableRefreshKey++; 
    
        // Force the whole component to refresh as well
        $this->dispatch('$refresh');
    }
    

    public function mount(): void
    {
        // Initialize the form state
        $this->form->fill();
    }


    public function getFormSchema(): array
    {
        return [
            FileUpload::make('uploaded_file')
                ->label('Upload File (.xls, .xlsx, .qif, .txt)')
                ->required()
                // ->getUploadedFileNameForStorageUsing(fn($file) => $file->getClientOriginalName())
                ->storeFiles(false)
                ->preserveFilenames()
                ->multiple(false)
                // ->live()
                ->rules([
                    'required',
                    'file',
                    'max:10240', // 10MB
                ])
                ,
            TextInput::make('account_number')
                ->label('Account Number')
                ->rule('regex:/^[0-9]{1,20}$/')
                ->placeholder('Enter your 16-digit account number')
                ->required(),

            TextInput::make('url')
                ->label('API Base URL')
                ->placeholder('example.com')
                ->required(),

            DatePicker::make('from_date')
                ->label('From Date')
                ->required()
                ->native(false),

            DatePicker::make('to_date')
                ->label('To Date')
                ->required()
                ->native(false)
        ,
                
                ];
        
    }



   // ✅ Main comparison submission
   // Inside CBrsComparison class
    public function submitComparison(): void
    {
        try {
            $data = $this->form->getState();

            // 1️⃣ Basic validation
            if (
                empty($data['uploaded_file']) ||
                empty($data['account_number']) ||
                empty($data['url']) ||
                empty($data['from_date']) ||
                empty($data['to_date'])
            ) {
                Notification::make()
                    ->title('⚠️ Missing Required Fields')
                    ->body('Please fill all inputs and upload a file.')
                    ->danger()
                    ->send();
                return;
            }

           // 2️⃣ Build dynamic URL
        $userUrl = trim($data['url']);
        if (!Str::startsWith($userUrl, ['http://', 'https://'])) {
            $userUrl = 'https://' . $userUrl;
        }
        $userUrl = rtrim($userUrl, '/');
        $apiUrl = Str::contains($userUrl, '/api/')
            ? $userUrl
            : $userUrl . '/api/default/home/brs';

        // 3️⃣ Prepare uploaded file
        $fileObject = $data['uploaded_file'];
        if (!($fileObject instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)) {
            throw new \Exception('Invalid file upload.');
        }

        $fileRealPath = $fileObject->getRealPath();
        $fileName = $fileObject->getClientOriginalName();
        $fileExt = strtolower($fileObject->getClientOriginalExtension());

        // 4️⃣ Send POST request to API
        Log::info('📤 Posting to API:', [
            'url' => $apiUrl,
            'bill_number' => $data['account_number'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
        ]);

        $response = Http::attach(
            'file', file_get_contents($fileObject->getRealPath()), $fileObject->getClientOriginalName()
        )
            ->post($apiUrl, [
                'bill_number' => $data['account_number'],
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'file_name' => $fileObject->getClientOriginalName(),
            ]);

        Log::info('API Raw Response', ['body' => $response->body()]);
        $apiData = $response->json();

        // 5️⃣ Validate API response
        if (!isset($apiData['status']) || $apiData['status'] !== true || empty($apiData['data'])) {
            throw new \Exception($apiData['message'] ?? 'Missing required parameters');
        }

        // 6️⃣ Bank data
        $bankData = collect($apiData['data'])->map(fn($item) => [
            'tra_id' => $item['tra_id'] ?? null,
            'tra_date' => $item['tra_date'] ?? null,
            'tra_amount' => (float)($item['tra_amount'] ?? 0),
            'tra_voucher_number' => $item['tra_voucher_number'] ?? null,
            'tra_narration' => trim($item['tra_narration'] ?? ''),
        ]);

        // 7️⃣ Ledger (QIF or XLSX)
        $ledgerData = $this->readFile($fileRealPath, $fileExt);

        if ($ledgerData->isEmpty()) {
            throw new \Exception('No transactions found in uploaded file.');
        }

        // 8️⃣ Comparison logic (exact match)
        $matched = collect();
        $unmatched = collect();
        // 🆕 Add index tracking
        $ledgerDataWithIndex = $ledgerData->values()->map(function ($item, $index) {
            $item['original_index'] = $index; // Store the original index
            return $item;
        });

        foreach ($ledgerDataWithIndex as $line) { // Use the indexed collection
            $found = false;
            // ... (rest of the exact match logic remains the same) ...
            $amount = (float) str_replace([',', '+', '-'], '', $line['amount']);
            $narration = preg_replace('/\s+/', '', strtolower(trim($line['narration'])));

            foreach ($bankData as $record) {
                $recordAmount = (float) str_replace([',', '+', '-'], '', $record['tra_amount']);
                $recordNarr = preg_replace('/\s+/', '', strtolower(trim($record['tra_narration'])));

                if ($recordNarr === $narration && abs($recordAmount - $amount) < 0.01) {
                    $matched->push([
                        'tra_id' => $record['tra_id'],
                        'tra_date' => $line['date'],
                        'tra_narration' => $line['narration'],
                        'tra_amount' => $line['amount'],
                        'bank_date' => $record['tra_date'],
                        'bank_amount' => $record['tra_amount'],
                        'tra_voucher_number' => $record['tra_voucher_number'],
                        'original_index' => $line['original_index'], // 🆕 Keep the index
                    ]);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $unmatched->push($line);
            }
        }
        $getType = function ($amount) {
            return substr(trim((string) $amount), 0, 1) === '+' ? 'Cr' : 'Dr';
        };

       // 🆕 Build Matched Results
        $this->results['matched'] = $matched->values()->map(function ($row, $index) use ($getType) {
            $amount = $row['tra_amount'] ?? 0;
            return [
                'original_index' => $row['original_index'] ?? $index,
                'tra_id' => $row['tra_id'],
                'tra_voucher_number' => $row['tra_voucher_number'],
                'narration' => $row['tra_narration'] ?? '',
                'date' => $row['tra_date'] ?? '',
                'amount' => $amount,
                'type' => $getType($amount),
                'color' => $getType === 'Cr' ? 'text-green-600' : 'text-red-600',
                'manual_verified' => false, // ✅ system matched
                'from_unmatched' => false,  // ✅ flag to detect later
            ];
        })->toArray(); // 🆕 Convert to array

        // 🆕 Build Unmatched Results & Populate Unmatched Map
        $unmatchedItems = $unmatched->values()->map(function ($row, $index) use ($getType) {
            $amount = $row['amount'] ?? 0;
            return [
                'original_index' => $row['original_index'] ?? $index,
                'tra_id' => '-',
                'tra_voucher_number' => '-',
                'narration' => $row['narration'] ?? '',
                'date' => $row['date'] ?? '',
                'amount' => $amount,
                'type' => $getType($amount),
                'manual_verified' => false,
                'from_unmatched' => false,
            ];
        })->toArray();

            // 🆕 Populate unmatched map (key is the original index)
            // $this->unmatchedMap[$row['original_index']] = $item;
            // return $item;
       
        $this->results['unmatched'] = $unmatchedItems;
        $this->unmatchedMap = collect($unmatchedItems)
        ->keyBy('original_index')
        ->sortKeys()     // preserve original file order
        ->toArray();

        // 🆕 Store unmatched results using values from the map (for initial table display)
        $this->results['unmatched'] = array_values($this->unmatchedMap);
        
        // ... (rest of the submitComparison logic) ...
        
        // 🆕 Update Counts
        $this->ledgerCount = $ledgerData->count();
        $this->apiCount = $bankData->count();
        $this->matchedCount = $matched->count();
        $this->unmatchedCount = $unmatched->count();

        $this->dispatch('$refresh'); // ✅ Force refresh after comparison   

        Notification::make()
            ->title('✅ Comparison Completed')
            ->body("Matched: {$this->matchedCount} | Unmatched: {$this->unmatchedCount}")
            ->success()
            ->send();
        } catch (\Throwable $e) {
            Log::error('Comparison Failed', ['error' => $e->getMessage()]);
            $this->results = ['matched' => [], 'unmatched' => []];
            Notification::make()
                ->title('❌ Comparison Failed')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
     
    public function verifyTransaction(int $originalIndex): void
    {
        if (!isset($this->unmatchedMap[$originalIndex])) return;

        $item = $this->unmatchedMap[$originalIndex];
        
        // ✅ MUST be this reactive method to force Livewire to see the change
        $this->unmatchedMap = collect($this->unmatchedMap)
                                ->forget($originalIndex)
                                ->toArray(); 
        
        // Move to matched
        $item['from_unmatched'] = true;
        $item['manual_verified'] = true;
        array_push($this->results['matched'], $item);

        $this->refreshTables(); // Triggers $this->dispatch('$refresh')

        Notification::make()
            ->title('✅ Verified')
            ->body('Transaction moved to matched list.')
            ->success()
            ->send();
    }
    public function revertTransaction(int $originalIndex): void
    {
        // Find and remove from matched
        $keyToRemove = null;
        $itemToRevert = null;

        foreach ($this->results['matched'] as $key => $item) {
            if (($item['original_index'] ?? null) == $originalIndex) {
                $keyToRemove = $key;
                $itemToRevert = $item;
                break;
            }
        }

        if (is_null($keyToRemove)) return;

        unset($this->results['matched'][$keyToRemove]);

        // Mark as unverified
        $itemToRevert['from_unmatched'] = false;
        $itemToRevert['manual_verified'] = false;

        // Restore in the exact same position by index key
        $this->unmatchedMap[$originalIndex] = $itemToRevert;
        ksort($this->unmatchedMap);

        // Force full array re-assignment
        $this->results['unmatched'] = array_values($this->unmatchedMap);
        $this->results['matched'] = array_values($this->results['matched']);

        $this->matchedCount = count($this->results['matched']);
        $this->unmatchedCount = count($this->results['unmatched']);

        $this->dispatch('$refresh');

        Notification::make()
            ->title('↩️ Reverted')
            ->body('Transaction restored to its original place.')
            ->success()
            ->send();
    }

    public function downloadManualBackup(): ?StreamedResponse
    {
    try {
            $manualMatches = collect($this->results['matched'] ?? [])
                ->filter(fn($row) => ($row['manual_verified'] ?? false) === true)
                ->values();

            if ($manualMatches->isEmpty()) {
                Notification::make()
                    ->title('⚠️ No Manual Matches Found')
                    ->body('There are no manually verified transactions to back up.')
                    ->warning()
                    ->send();

                return null; // ✅ allowed because of "?StreamedResponse"
            }

            $fileName = 'manual_matched_backup_' . date('Ymd_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '";',
            ];

            $callback = function () use ($manualMatches) {
                $file = fopen('php://output', 'w');
                fputcsv($file, [
                    'original_index', 'tra_id', 'tra_voucher_number', 'narration',
                    'date', 'amount', 'type', 'manual_verified', 'from_unmatched',
                ]);

                foreach ($manualMatches as $row) {
                    fputcsv($file, [
                        $row['original_index'] ?? '-',
                        $row['tra_id'] ?? '-',
                        $row['tra_voucher_number'] ?? '-',
                        $row['narration'] ?? '',
                        $row['date'] ?? '',
                        $row['amount'] ?? '',
                        $row['type'] ?? '',
                        (int)($row['manual_verified'] ?? 1),
                        (int)($row['from_unmatched'] ?? 1),
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Throwable $e) {
            Notification::make()
                ->title('❌ Download Failed')
                ->body('Something went wrong while generating the backup file.')
                ->danger()
                ->send();

            return null; // ✅ also allowed
        }
    }
/**
 * Uploads a backup CSV and appends the transactions to the matched list.
 */
    public function uploadBackup(): void
    {
        $fileObject = $this->backup_file;
        if (!$fileObject) {
            Notification::make()
                ->title('⚠️ Upload Failed')
                ->body('No backup file selected.')
                ->danger()
                ->send();
            return;
        }

        $filePath = method_exists($fileObject, 'getRealPath')
            ? $fileObject->getRealPath()
            : $fileObject->getPathname();

        if (!file_exists($filePath) || !is_readable($filePath)) {
            Notification::make()
                ->title('⚠️ Could not read uploaded backup file.')
                ->danger()
                ->send();
            return;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            Notification::make()
                ->title('⚠️ Failed to open file.')
                ->danger()
                ->send();
            return;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            Notification::make()
                ->title('⚠️ Invalid CSV headers.')
                ->danger()
                ->send();
            return;
        }

        $rows = [];
        $recordCount = 0;
        $baseId = random_int(100000000, 999999999);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) continue;

            $combined = array_combine($headers, $row);
            $amountRaw = str_replace(',', '', $combined['amount'] ?? $combined['Amount'] ?? '0');

            $rows[] = [
                'original_index'     => ($baseId * 10) + $recordCount,
                'tra_id'             => $combined['tra_id'] ?? $combined['Id'] ?? '-',
                'tra_voucher_number' => $combined['tra_voucher_number'] ?? $combined['Voucher No'] ?? '-',
                'narration'          => $combined['narration'] ?? $combined['Narration'] ?? '',
                'date'               => $combined['date'] ?? $combined['Date'] ?? '',
                'amount'             => (float)$amountRaw,
                'type'               => ($combined['type'] ?? $combined['Cr/Dr'] ?? 'Dr.') === 'Cr.' ? 'Credit' : 'Debit',
                'manual_verified'    => true,
                'from_unmatched'     => true,
            ];
            $recordCount++;
        }
        fclose($handle);

        if (empty($rows)) {
            Notification::make()
                ->title('⚠️ No valid rows found in backup file.')
                ->danger()
                ->send();
            return;
        }

        // ✅ Keep appended rows in a dedicated property
        $this->manualMatched = array_merge($this->manualMatched, $rows);

        // ✅ Merge once with results safely
        $this->results['matched'] = array_merge($this->results['matched'] ?? [], $this->manualMatched);

        $this->matchedCount = count($this->results['matched']);
        $this->unmatchedCount = count($this->results['unmatched'] ?? []);
        $this->refreshTables();

        Notification::make()
            ->title('✅ Backup Uploaded')
            ->body("{$recordCount} manual records appended.")
            ->success()
            ->send();

        $this->backup_file = null;
    }

    public function exportCurrentViewToCsv(string $mode): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Determine the data source based on the mode parameter
        $dataToExport = $this->results[$mode] ?? [];
        $isMatched = $mode === 'matched';
        
        $modeLabel = $isMatched ? 'Matched' : 'Unmatched';
        $fileName = strtolower($modeLabel) . '_transactions_' . date('Ymd_His') . '.csv';

        if (empty($dataToExport)) {
            Notification::make()
                ->title("⚠️ No {$modeLabel} Data")
                ->body("There are no transactions in the {$modeLabel} list to export.")
                ->warning()
                ->send();
            return response()->stream(fn() => '', 200, ['Content-Type' => 'text/plain']);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '";',
        ];

        $callback = function () use ($dataToExport, $isMatched) {
            $file = fopen('php://output', 'w');

            // Base CSV Headers
            $csvHeaders = [
                'Sl No',
                'Original Index',
                'Date',
                'Narration',
                'Amount',
                'Cr/Dr',
                'Transaction ID',
                'Voucher Number',
            ];

            // Add extra headers only for the matched list
            if ($isMatched) {
                $csvHeaders[] = 'Manually Verified';
                $csvHeaders[] = 'From Unmatched List';
            }
            
            fputcsv($file, $csvHeaders);

            // Write data rows
            $slNo = 1;
            foreach ($dataToExport as $row) {
                $rowData = [
                    $slNo++,
                    $row['original_index'] ?? '-',
                    $row['date'] ?? '',
                    $row['narration'] ?? '',
                    $row['amount'] ?? '',
                    $row['type'] ?? '',
                    $row['tra_id'] ?? '-',
                    $row['tra_voucher_number'] ?? '-',
                ];

                // Add extra columns only for the matched list
                if ($isMatched) {
                    $rowData[] = ($row['manual_verified'] ?? false) ? 'Yes' : 'No';
                    $rowData[] = ($row['from_unmatched'] ?? false) ? 'Yes' : 'No';
                }
                
                fputcsv($file, $rowData);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function refreshTables(): void
    {
        // 1. Recalculate the arrays for table display
        $this->results['matched'] = array_values($this->results['matched']);
        $this->results['unmatched'] = collect($this->unmatchedMap)
                                        ->sortKeys() 
                                        ->values()
                                        ->toArray();

        // 2. Update counts
        $this->matchedCount   = count($this->results['matched']);
        $this->unmatchedCount = count($this->results['unmatched']);

        // 3. Force Re-render
        
        // 🎯 CRITICAL FIX: Attempt to directly call the Livewire refresh method on the component instance.
        // This is the most reliable way to force the DOM to update when dispatch() fails.
        if (method_exists($this, 'getLivewire')) {
            $this->getLivewire()->dispatch('$refresh');
        } else {
            // Fallback for Page components
            $this->dispatch('$refresh'); 
        }
        
    }
    // ✅ Table Display
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sl_no')
                    ->label('Sl No')
                    ->sortable(),

                TextColumn::make('tra_id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('tra_voucher_number')
                    ->label('Voucher No')
                    ->sortable(),

                TextColumn::make('narration')
                    ->label('Narration')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('date')
                    ->label('Date')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('INR', true)
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Cr/Dr')
                    ->color(fn($record) => ($record['tra_amount'] ?? 0) > 0 ? 'success' : 'danger'),
                // TextColumn::make('original_index')
                //         ->label('Original Index')
                //         ->sortable()
                //         ->hidden(true),
                    
                    
            ])

            // ✅ This provides data from arrays instead of Eloquent
        ->records(function () {
            $sourceData = $this->viewMode === 'matched' 
                ? ($this->results['matched'] ?? []) 
                : ($this->results['unmatched'] ?? []);

            return collect($sourceData)
                ->values()
                ->map(function ($item, $i) {
                    // 🎯 CRITICAL FIX: Add a unique 'id' field based on original_index.
                    // Filament's array data source will use this 'id' field as the row key (wire:key).
                    $item['id'] = $item['original_index']; 
                    $item['sl_no'] = $i + 1;
                    return $item;
                })
                ->toArray();
        })

        
        ->headerActions([
            // New Grouped Action
            ActionGroup::make([

                // 1. Normal Export (matched List)
                Action::make('export_matched')
                    ->label('Export CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->button()
                    ->visible(fn () => $this->viewMode === 'matched') // Only visible in unmatched view
                    ->action(fn () => $this->exportCurrentViewToCsv('matched')),
                // 2. Normal Export (Unmatched List)
                 Action::make('export_unmatched')
                    ->label('Export CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('primary')
                    ->button()
                    ->visible(fn () => $this->viewMode === 'unmatched') // Only visible in unmatched view
                    ->action(fn () => $this->exportCurrentViewToCsv('unmatched')),
                    //->color('info'),
                
                // 3. Download Manual Backup (Matched List)
                Action::make('download_manual_backup')
                    ->label('Download Backup')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('warning')
                    ->button()
                    ->visible(fn () => $this->viewMode === 'matched') // Only visible in matched view
                    ->action('downloadManualBackup'),

                //4. Upload Backup (Matched List) - Uses a modal for file upload
                Action::make('upload_backup')
                    ->label('Upload Backup')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('info')
                    ->button()
                    ->visible(fn () => $this->viewMode === 'matched') // Only visible in matched view
                    ->form([
                        FileUpload::make('backup_file')
                            ->label('Upload Backup File')
                            ->required()
                            ->storeFiles(false)
                            ->preserveFilenames()
                            ->acceptedFileTypes(['text/csv'])
                            ->live(), // Bind to the backup_file property
                    ])
                    ->modalSubmitActionLabel('Upload')
                    ->action(function (array $data) {
                        // ✅ Read file content manually, not reactively
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

                        // ✅ Read full file content at once
                        $csvData = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                        if (count($csvData) <= 1) {
                            Notification::make()
                                ->title('⚠️ Backup file is empty.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Pass parsed content directly to uploadBackup() using public property
                        $this->backup_file = $fileObject;

                        // ✅ Call uploadBackup() AFTER file is fully read
                        $this->uploadBackup();
                    }),

            ])
            ->label('Actions')
            ->icon('heroicon-m-ellipsis-vertical')
            ->button()
            ->color('gray')
            ->visible(fn () => $this->ledgerCount > 0), // Show only when data exists
        ])

        // 🆕 Add table actions
            ->actions([
                Action::make('verify')
                    ->label('Verify')
                    ->color('success')
                    ->button()
                    //->requiresConfirmation()
                    ->visible(fn ($record) =>
                        $this->viewMode === 'unmatched' && is_array($record)
                    )
                    ->action(function ($record) {
                        if (is_array($record) && isset($record['original_index'])) {
                            $this->verifyTransaction($record['original_index']);
                        }
                    }),

                // ✅ REVERT only for manually verified rows in matched
                Action::make('revert')
                    ->label('Revert')
                    ->color('warning')
                    ->button()
                   // ->requiresConfirmation()
                    ->visible(fn($record) =>
                        $this->viewMode === 'matched'
                        && is_array($record)
                        && ($record['from_unmatched'] ?? false) === true
                    )
                    ->action(function ($record) {
                        if (is_array($record) && isset($record['original_index'])) {
                            $this->revertTransaction($record['original_index']);
                        }
                    }),
                ]);
    }

    private function readFile(string $filePath, string $ext): \Illuminate\Support\Collection
    {
        $data = collect();

        if ($ext === 'qif') {
            $file = fopen($filePath, 'r');
            $amt = $date = $memo = null;
            while (($line = fgets($file)) !== false) {
                $line = trim($line);
                if ($line === '') continue;

                if ($line[0] === 'T') $amt = substr($line, 1);
                if ($line[0] === 'D') $date = substr($line, 1);
                if ($line[0] === 'M') {
                    $memo = substr($line, 1);
                    $data->push(['narration' => $memo, 'amount' => $amt, 'date' => $date]);
                }
            }
            fclose($file);
        }

        // TODO: handle XLS/XLSX if needed

        return $data;
    }


   

    // ✅ File parsers
    

    protected function parseExcel(string $filePath): Collection
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        array_shift($rows);
        return collect($rows)->map(fn($r) => [
            'date' => $r[0] ? date('Y-m-d', strtotime($r[0])) : null,
            'narration' => trim($r[1] ?? ''),
            'amount' => (float)($r[2] ?? 0),
        ]);
    }

    protected function parseCsv(string $filePath): Collection
    {
        $rows = array_map('str_getcsv', file($filePath));
        array_shift($rows);
        return collect($rows)->map(fn($r) => [
            'date' => $r[0] ?? null,
            'narration' => trim($r[1] ?? ''),
            'amount' => (float)($r[2] ?? 0),
        ]);
    }

    protected function parseQif(string $filePath): Collection
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        return collect($lines)->map(fn($l) => [
            'date' => null,
            'narration' => $l,
            'amount' => 0,
        ]);
    }
}