<?php

namespace App\Filament\Widgets;

use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;


class BrsTypeSelector extends Widget implements HasForms
{
    use InteractsWithForms;

    protected  string $view = 'filament.widgets.brs-type-selector';
    protected int | string | array $columnSpan = 'full';

    public ?string $brsType = null;
    // ✅ Show this widget only for admins
    public static function canView(): bool
    {
        $user = Auth::user();
        return $user && $user->user_type == 1;
    }
    // Define a simple form for the selector
    protected function getFormSchema(): array
    {
        return [
            Select::make('brsType')
                ->label('BRS Types')
                ->placeholder('Select Type')
                ->options([
                    'i-bank' => 'i-Bank',
                    'c-bank' => 'c-Bank',
                ])
                ->reactive() // Make the field react to changes instantly
                ->afterStateUpdated(fn ($state) => $this->redirectToBrsPage($state))
                ->disableLabel(), // Label is in the card title
        ];
    }

    // Method to redirect the user
    public function redirectToBrsPage(string $type): void
    {
        if ($type === 'i-bank') {
            $this->redirect(route('filament.admin.pages.i-brs-comparison'));
        } elseif ($type === 'c-bank') {
            $this->redirect(route('filament.admin.pages.c-brs-comparison'));
        }
    }
}