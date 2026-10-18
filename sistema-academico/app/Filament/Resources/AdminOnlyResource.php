<?php

namespace App\Filament\Resources;

use App\Models\User;
use Filament\Resources\Resource;
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
}
