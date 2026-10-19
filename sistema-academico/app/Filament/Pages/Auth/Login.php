<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    public function getHeading(): string|Htmlable
    {
        return 'Acceso institucional';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Sistema Académico USIL';
    }
}
