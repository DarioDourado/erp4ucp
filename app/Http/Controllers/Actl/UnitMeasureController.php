<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UnitMeasure;
use Auth;
use Illuminate\Support\Carbon;

class UnitMeasureController extends Controller
{
    public function UnitMeasureAll()
    {
        $unitMeasures = UnitMeasure::latest()->get();
        return view('backend.unitMeasure.unitMeasure_all', compact('unitMeasures'));
    }

    public function UnitMeasureAdd()
    {
        return view('backend.unitMeasure.unitMeasure_add');
    }

    public function UnitMeasureStore(Request $request)
    {
        $request->validate([
            'unity' => 'required|unique:UnitMeasure,unity',
        ]);

        UnitMeasure::insert([
            'unity'      => $request->unity,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Unidade de Medida Adicionada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('unitMeasure.all')->with($notification);
    }

    public function UnitMeasureEdit($id)
    {
        $unitMeasure = UnitMeasure::findOrFail($id);
        return view('backend.unitMeasure.unitMeasure_edit', compact('unitMeasure'));
    }

    public function UnitMeasureUpdate(Request $request)
    {
        $unitMeasure_id = $request->id;

        UnitMeasure::findOrFail($unitMeasure_id)->update([
            'unity'      => $request->unity,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Unidade de Medida Actualizada Corretamente.',
            'alert-type' => 'info',
        ];

        return redirect()->route('unitMeasure.all')->with($notification);
    }

    public function UnitMeasureDelete($id)
    {
        UnitMeasure::findOrFail($id)->delete();

        $notification = [
            'message'    => 'Unidade de Medida Eliminada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('unitMeasure.all')->with($notification);
    }
}
