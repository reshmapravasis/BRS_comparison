<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use App\Models\User;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('password')
                    ->password()
                    //->minLength(8)
                    ->visibleOn('create')
                    ->required(),
                TextInput::make('contact')
                    ->label('Phone')
                    ->required(),
                TextInput::make('bankname')
                    ->label('Bankname')
                    ->required(),
                Select::make('user_type')
                    ->label('User Type')
                    ->placeholder('Select User type')
                    ->options([
                        1 => 'Admin',
                        0 => 'Normal User',
                    ])
                    //->default(0)
                    ->required()
                    ->native(false)
                    ->reactive(),
                Select::make('brs_type')
                    ->label('BRS_type')
                    ->placeholder('Select BRS Type')
                    ->searchable()
                    ->options([
                        'qif&idbi' => 'qif&idbi',
                        'xlx&cbank' => 'xlx&cbank',
                        'xls&ibank' => 'xls&ibank',
                                                //default => 'Admin',
                    ])
                        
                    ,
                 
                
            ]);
    }
}
