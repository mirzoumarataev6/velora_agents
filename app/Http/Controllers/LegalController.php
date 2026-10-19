<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Публичные страницы условий использования и политики конфиденциальности.
 * Доступны без авторизации — на них ссылается форма регистрации.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        return view('legal.terms');
    }

    public function privacy(): View
    {
        return view('legal.privacy');
    }
}
