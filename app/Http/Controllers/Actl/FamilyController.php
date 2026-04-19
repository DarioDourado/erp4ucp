<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Family;
use Auth;
use Illuminate\Support\Carbon;

class FamilyController extends Controller
{
    public function FamilyAll()
    {
        $families = Family::latest()->get();
        return view('backend.family.family_all', compact('families'));
    }

    public function FamilyAdd()
    {
        return view('backend.family.family_add');
    }

    public function FamilyStore(Request $request)
    {
        $request->validate([
            'family' => 'required|unique:Family,family',
        ]);

        Family::insert([
            'family'     => $request->family,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Família Adicionada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('family.all')->with($notification);
    }

    public function FamilyEdit($id)
    {
        $family = Family::findOrFail($id);
        return view('backend.family.family_edit', compact('family'));
    }

    public function FamilyUpdate(Request $request)
    {
        $family_id = $request->id;

        Family::findOrFail($family_id)->update([
            'family'     => $request->family,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Família Actualizada Corretamente.',
            'alert-type' => 'info',
        ];

        return redirect()->route('family.all')->with($notification);
    }

    public function FamilyDelete($id)
    {
        Family::findOrFail($id)->delete();

        $notification = [
            'message'    => 'Família Eliminada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('family.all')->with($notification);
    }
}
