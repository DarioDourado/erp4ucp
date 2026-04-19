<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PostalCode;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class SupplierController extends Controller
{
    public function SupplierAll()
    {
        $suppliers = Supplier::latest()->get();
        return view('backend.supplier.supplier_all', compact('suppliers'));
    }

    public function SupplierAdd()
    {
        $postalCodes = PostalCode::latest()->get();
        return view('backend.supplier.supplier_add', compact('postalCodes'));
    }

    public function SupplierStore(Request $request)
    {
        $request->validate([
            'code' => 'required|unique:Supplier,code',
            'name' => 'required',
        ]);

        Supplier::insert([
            'code'       => $request->code,
            'name'       => $request->name,
            'nif'        => $request->nif,
            'address1'   => $request->address1,
            'address2'   => $request->address2,
            'town'       => $request->town,
            'postalCode' => $request->postalCode,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Fornecedor Adicionado Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('supplier.all')->with($notification);
    }

    public function SupplierEdit($id)
    {
        $postalCodes = PostalCode::all();
        $supplier    = Supplier::findOrFail($id);
        
        return view('backend.supplier.supplier_edit', compact('supplier', 'postalCodes'));
    }

    public function SupplierUpdate(Request $request)
    {
        $supplier_id = $request->id;

        Supplier::findOrFail($supplier_id)->update([
            'code'       => $request->code,
            'name'       => $request->name,
            'nif'        => $request->nif,
            'address1'   => $request->address1,
            'address2'   => $request->address2,
            'town'       => $request->town,
            'postalCode' => $request->postalCode,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'Fornecedor Actualizado Corretamente.',
            'alert-type' => 'info',
        ];

        return redirect()->route('supplier.all')->with($notification);
    }

    public function SupplierDelete($id)
    {
        Supplier::findOrFail($id)->delete();

        $notification = [
            'message'    => 'Fornecedor Eliminado Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('supplier.all')->with($notification);
    }
}
