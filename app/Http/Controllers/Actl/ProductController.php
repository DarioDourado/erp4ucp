<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Product;
use App\Models\Family;
use App\Models\UnitMeasure;
use App\Models\TaxRate; 

use Auth;
use Illuminate\Support\Carbon;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

use Illuminate\Support\Facades\File;


class ProductController extends Controller
{
    public function ProductAll(){
        $products = Product::latest()->get();
        return view('backend.product.product_all',compact('products'));
    } // End Method 

    public function ProductAdd(){
        $families = Family::all();
        $units = UnitMeasure::latest()->get();
        $taxRates = TaxRate::latest()->get();
        return view ('backend.product.product_add', compact('families','units','taxRates'));
    }

    public function ProductStore(Request $request){
        $request->validate([
            'code' => 'required',
            'description' => 'required',
            'product_family' => 'required',
            'product_unit' => 'required',
            'taxRateCode_Product' => 'required',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $save_url = 'upload/no_image.jpg';

        try {

            if ($request->hasFile('image')) {

                $imageFile = $request->file('image');
                $transformName = hexdec(uniqid()) . '.' . $imageFile->getClientOriginalExtension();

                $destinationPath = public_path('upload/product');

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0777, true);
                }

                $manager = new \Intervention\Image\ImageManager(
                    new \Intervention\Image\Drivers\Gd\Driver()
                );

                $image = $manager->read($imageFile->getPathname());
                $image->resize(200, 200);
                $image->save($destinationPath . '/' . $transformName);

                $save_url = 'upload/product/' . $transformName;
            }

            Product::insert([
                'code' => $request->code,
                'description' => $request->description,
                'family' => $request->product_family,
                'unit' => $request->product_unit,
                'taxRateCode' => $request->taxRateCode_Product,
                'image' => $save_url,
                'created_by' => Auth::id(),
                'created_at' => Carbon::now(),
            ]);

            return redirect()->route('product.all')->with([
                'message' => 'Product Inserted Successfully.',
                'alert-type' => 'success'
            ]);

        } catch (\Exception $e) {

            if ($save_url !== 'upload/no_image.jpg' && file_exists(public_path($save_url))) {
                unlink(public_path($save_url));
            }
        }
    }   
    
    public function ProductEdit($id){
        $product = Product::findOrFail($id);
        $families = Family::all();
        $units = UnitMeasure::latest()->get();
        $taxRates = TaxRate::latest()->get();
        return view('backend.product.product_edit',compact('product','families','units','taxRates'));
    }

    public function ProductUpdate(Request $request){
        $request->validate([
            'id' => 'required|integer|exists:Product,id',
            'code' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'product_family' => 'required|string|max:255',
            'product_unit' => 'required|string|max:255',
            'taxRateCode_Product' => 'required',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'code.required' => 'Introduza o código',
            'description.required' => 'Introduza a descrição',
            'product_family.required' => 'Selecione a família',
            'product_unit.required' => 'Selecione a unidade',
            'taxRateCode_Product.required' => 'Selecione o IVA',
            'image.image' => 'O ficheiro tem de ser uma imagem',
            'image.mimes' => 'A imagem deve ser JPG, JPEG, PNG ou WEBP',
            'image.max' => 'A imagem não pode ultrapassar 2MB',
        ]);

        $product = Product::findOrFail($request->id);

        $oldImage = $product->image;
        $newImagePath = $oldImage;

        // 1) O utilizador pediu para remover a imagem atual
        if ($request->remove_image == '1') {
            if (!empty($oldImage) && file_exists(public_path($oldImage))) {
                unlink(public_path($oldImage));
            }

            $newImagePath = null;
        }

        // 2) O utilizador escolheu uma nova imagem
        if ($request->hasFile('image')) {
            // Apaga a antiga, se existir
            if (!empty($oldImage) && file_exists(public_path($oldImage))) {
                unlink(public_path($oldImage));
            }

            $file = $request->file('image');
            $filename = hexdec(uniqid()) . '.' . $file->getClientOriginalExtension();
            $uploadPath = 'upload/product/';
            $file->move(public_path($uploadPath), $filename);

            $newImagePath = $uploadPath . $filename;
        }

        $product->update([
            'code' => $request->code,
            'description' => $request->description,
            'family' => $request->product_family,
            'unit' => $request->product_unit,
            'taxRateCode' => $request->taxRateCode_Product,
            'image' => $newImagePath,
            'updated_by' => auth()->id(),
        ]);

        $notification = [
            'message' => 'Artigo atualizado com sucesso',
            'alert-type' => 'success'
        ];
        return redirect()->route('product.all')->with($notification);
    }    


    public function ProductDelete($id)
    {
        $product = Product::findOrFail($id);

        // apagar imagem do disco (se existir)
        if (!empty($product->image) && File::exists(public_path($product->image))) {
            File::delete(public_path($product->image));
        }

        // apagar registo da base de dados
        $product->delete();

        $notification = [
            'message' => 'Artigo eliminado com sucesso',
            'alert-type' => 'success'
        ];

        return redirect()->back()->with($notification);
    }

}

