<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Tables\Table;
use Filament\Actions\EditAction;
use Filament\Tables\Filters\Filter;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Enums\FiltersLayout;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('last_name')
                          ->label('Фамилия')
                          ->searchable()
                          ->sortable()
                          ->toggleable(),
                TextColumn::make('first_name')
                          ->label('Имя')
                          ->searchable()
                          ->sortable()
                          ->toggleable(),
                TextColumn::make('phone')
                          ->label('Телефон')
                          ->limit(25)
                          ->tooltip(static fn($record) => $record?->phone)
                          ->searchable()
                          ->sortable()
                          ->toggleable(),
                TextColumn::make('notes')
                          ->label('Особые приметы')
                          ->limit(25)
                          ->tooltip(static fn($record) => $record?->notes)
                          ->toggleable(),
                IconColumn::make('docs')
                          ->label('Документы')
                          ->alignCenter()
                          ->boolean()
                          ->state(fn($record) => filled($record->docs))
                          ->trueColor('primary')
                          ->falseColor('danger')
                          ->sortable()
                          ->toggleable(),
                TextColumn::make('created_at')
                          ->dateTime()
                          ->sortable()
                          ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                          ->dateTime()
                          ->sortable()
                          ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('filter')
                      ->columns(5)
                      ->schema([
                          TextInput::make('last_name')
                                   ->label('Фамилия'),

                          TextInput::make('first_name')
                                   ->label('Имя'),

                          TextInput::make('phone')
                                   ->label('Телефон'),

                          TextInput::make('notes')
                                   ->label('Особые приметы'),

                          Select::make('docs')
                                ->label('Документы')
                                ->placeholder('Все')
                                ->options([
                                    '1' => 'Есть документы',
                                    '0' => 'Нет документов',
                                ]),
                      ])
                      ->query(static function ($query, array $data) {
                          return $query
                              ->when(
                                  filled($data['last_name'] ?? null),
                                  fn($query) => $query->where('last_name', 'like', "%{$data['last_name']}%")
                              )
                              ->when(
                                  filled($data['first_name'] ?? null),
                                  fn($query) => $query->where('first_name', 'like', "%{$data['first_name']}%")
                              )
                              ->when(
                                  filled($data['phone'] ?? null),
                                  fn($query) => $query->where('phone', 'like', "%{$data['phone']}%")
                              )
                              ->when(
                                  filled($data['notes'] ?? null),
                                  fn($query) => $query->where('notes', 'like', "%{$data['notes']}%")
                              )
                              ->when(
                                  filled($data['docs'] ?? null),
                                  function ($query) use ($data) {
                                      if ($data['docs'] === '1') {
                                          $query->whereNotNull('docs');
                                      }

                                      if ($data['docs'] === '0') {
                                          $query->whereNull('docs');
                                      }
                                  }
                              );
                      })
                      ->indicateUsing(function (array $data): array {
                          $indicators = [];

                          if (filled($data['last_name'] ?? null)) {
                              $indicators[] = Indicator::make('Фамилия: ' . $data['last_name'])
                                                       ->removeField('last_name');
                          }

                          if (filled($data['first_name'] ?? null)) {
                              $indicators[] = Indicator::make('Имя: ' . $data['first_name'])
                                                       ->removeField('first_name');
                          }

                          if (filled($data['phone'] ?? null)) {
                              $indicators[] = Indicator::make('Телефон: ' . $data['phone'])
                                                       ->removeField('phone');
                          }

                          if (filled($data['notes'] ?? null)) {
                              $indicators[] = Indicator::make('Особые приметы: ' . $data['notes'])
                                                       ->removeField('notes');
                          }

                          if (filled($data['docs'] ?? null)) {
                              $indicators[] = Indicator::make(
                                  $data['docs'] === '1'
                                      ? 'Документы: есть'
                                      : 'Документы: нет'
                              )->removeField('docs');
                          }

                          return $indicators;
                      }),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(1)
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
