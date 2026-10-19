<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AdminOnlyResource extends Resource
{
    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdminTI() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::currentUser()?->isAdminTI() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::currentUser()?->isAdminTI() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::currentUser()?->isAdminTI() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return static::currentUser()?->isAdminTI() ?? false;
    }

    protected static function dateRangeFilter(string $column, string $label): Filter
    {
        return Filter::make("rango_{$column}")
            ->label($label)
            ->form([
                DatePicker::make('desde')
                    ->label('Desde'),
                DatePicker::make('hasta')
                    ->label('Hasta'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(
                    $data['desde'] ?? null,
                    fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date),
                )
                ->when(
                    $data['hasta'] ?? null,
                    fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date),
                ));
    }
}
