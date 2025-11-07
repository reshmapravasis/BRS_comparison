<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;


class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('bankname'),
                BadgeColumn::make('user_type')
                    ->label('User Type')
                    ->colors([
                        'success' => 1,  // green for admin
                        'warning' => 0,  // yellow for normal user
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'Admin' : 'Normal User'),
                TextColumn::make('brs_type')
                    ->label('BRS_type')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'qif&idbi' => 'qif&idbi',
                        'xlx&cbank' => 'xlx&cbank',
                        'xls&ibank' => 'xls&ibank',
                        //default => 'Admin',
                    }),

            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
