<?php

namespace App\Http\Controllers\Teste;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class Teste01Controller extends Controller
{
    public function Facerca()
    {
        return view('acercade');
    }

    public function Fcontactos()
    {
        return view('contactos');
    }
}
