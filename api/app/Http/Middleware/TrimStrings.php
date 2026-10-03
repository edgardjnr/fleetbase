<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
        // senha digitada pela central em Lojas (LojasController): o login do portal não apara a senha, então ela não pode ser aparada ao gravar
        'senha',
    ];
}
