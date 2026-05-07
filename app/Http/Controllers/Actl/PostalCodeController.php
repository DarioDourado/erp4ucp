<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PostalCode;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class PostalCodeController extends Controller
{
    public function PostalCodeAll(){
        // $postalCosdes = PostalCode::all();
        $postalCodes = PostalCode::latest()->get();
        return view ('backend.postalCode.postalCode_all', compact('postalCodes'));
    }

    public function PostalCodeAdd(){
        return view ('backend.postalCode.postalCode_add');
    }
    
    public function PostalCodeStore(Request $request){

        PostalCode::insert([
            'postalCode' => $request->postalCode,
            'location' => $request->location,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'CP Adicionado Corretamente.',
            'alert-type' => 'success',
        ];

         return redirect()->route('postalCode.all')->with($notification);
    }

    public function PostalCodeEdit($id){
        $postalCode = PostalCode::findOrFail($id);
        return view('backend.postalCode.postalCode_edit', compact('postalCode'));
    }

    public function PostalCodeUpdate(Request $request){
        
        $postalCode_id = $request->id;

        PostalCode::findOrFail($postalCode_id)->update([
            'postalCode' => $request->postalCode,
            'location' => $request->location,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'CP Atualizado Corretamente.',
            'alert-type' => 'info',
        ];

         return redirect()->route('postalCode.all')->with($notification);
    }

     public function PostalCodeDelete($id){
        PostalCode::findOrFail($id)->delete();

        $notification = [
            'message'    => 'CP Eliminado Corretamente.',
            'alert-type' => 'success',
        ];

         return redirect()->route('postalCode.all')->with($notification);
    }
}

