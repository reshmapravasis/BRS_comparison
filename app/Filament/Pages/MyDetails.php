<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Illuminate\Database\Eloquent\Builder;
use App\Models\User;
use Filament\Forms;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\EditAction;


class MyDetails extends Page implements HasTable
{
    use InteractsWithTable;

    //protected static ?string $navigationIcon = 'heroicon-o-user';
        protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static ?string $navigationLabel = 'My Details';
    protected static ?string $title = 'My Account Details';
    protected  string $view = 'filament.pages.my-details';

    
    public static function shouldRegisterNavigation(): bool
    {
        // Show only for normal users
        return auth()->check() && auth()->user()->user_type == 0;
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query($this->getUserQuery())
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Name')->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->sortable(),
                Tables\Columns\TextColumn::make('contact')->label('Phone')->sortable(),
                Tables\Columns\TextColumn::make('bankname')->label('Bank Name')->sortable(),
                Tables\Columns\TextColumn::make('brs_type')->label('BRS Type'),
            ])
            ->actions([
                EditAction::make()
                    ->label('Edit Details')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required(),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(),
                        Forms\Components\TextInput::make('contact')
                            ->label('Phone')
                            ->required(),
                        Forms\Components\TextInput::make('bankname')
                            ->label('Bank Name')
                            ->required(),

                        // Forms\Components\TextInput::make('password')
                        //     ->label('Password')
                        //     ->password()
                        //     ->dehydrateStateUsing(fn($state) => filled($state) ? bcrypt($state) : null)
                        //     ->dehydrated(fn($state) => filled($state))
                        //     ->visible(fn($record) => auth()->user()->id === $record->id),

                        Forms\Components\TextInput::make('brs_type')
                            ->label('BRS Type')
                            ->disabled(), // readonly
                    ])
                    ->modalHeading('Edit My Details')
                    ->modalButton('Save Changes')
                    ->successNotificationTitle('Profile updated successfully!'),
            ])
            ->headerActions([])
            ->paginated(false); // Only one record shown
    }

    protected function getUserQuery(): Builder
    {
        $userId = auth()->id();
        return User::query()->where('id', $userId);
    }
}
