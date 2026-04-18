<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidaIdade
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $idade = $request->input('VIdade');
        if ($idade <= 18) {
            return redirect()->route('contactos.pagina');
        }
        
        return $next($request);
    }
}
