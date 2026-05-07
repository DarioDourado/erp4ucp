<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Family;
use App\Models\TaxRate;
use App\Models\UnitMeasure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ArticleController extends Controller
{
    public function ArticleAll()
    {
        $articles = Article::with(['unitMeasure', 'family', 'taxRate'])->latest()->get();

        return view('backend.article.article_all', compact('articles'));
    }

    public function ArticleAdd()
    {
        $unitMeasures = UnitMeasure::latest()->get();
        $families = Family::latest()->get();
        $taxRates = TaxRate::latest()->get();

        return view('backend.article.article_add', compact('unitMeasures', 'families', 'taxRates'));
    }

    public function ArticleStore(Request $request)
    {
        $request->validate([
            'code' => 'required|max:25|unique:Article,code',
            'description' => 'required|max:150',
            'unitMeasure_id' => 'required|exists:UnitMeasure,id',
            'family_id' => 'required|exists:Family,id',
            'taxRate_id' => 'required|exists:TaxRate,id',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $imagePath = $this->storeImage($request);

        Article::insert([
            'code' => $request->code,
            'description' => $request->description,
            'image' => $imagePath,
            'unitMeasure_id' => $request->unitMeasure_id,
            'family_id' => $request->family_id,
            'taxRate_id' => $request->taxRate_id,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message' => 'Artigo Adicionado Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('article.all')->with($notification);
    }

    public function ArticleEdit($id)
    {
        $article = Article::findOrFail($id);
        $unitMeasures = UnitMeasure::latest()->get();
        $families = Family::latest()->get();
        $taxRates = TaxRate::latest()->get();

        return view('backend.article.article_edit', compact('article', 'unitMeasures', 'families', 'taxRates'));
    }

    public function ArticleUpdate(Request $request)
    {
        $article = Article::findOrFail($request->id);

        $request->validate([
            'code' => [
                'required',
                'max:25',
                Rule::unique('Article', 'code')->ignore($article->id),
            ],
            'description' => 'required|max:150',
            'unitMeasure_id' => 'required|exists:UnitMeasure,id',
            'family_id' => 'required|exists:Family,id',
            'taxRate_id' => 'required|exists:TaxRate,id',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $imagePath = $article->image;

        if ($request->hasFile('image')) {
            $this->removeImageFile($article->image);
            $imagePath = $this->storeImage($request);
        }

        $article->update([
            'code' => $request->code,
            'description' => $request->description,
            'image' => $imagePath,
            'unitMeasure_id' => $request->unitMeasure_id,
            'family_id' => $request->family_id,
            'taxRate_id' => $request->taxRate_id,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message' => 'Artigo Actualizado Corretamente.',
            'alert-type' => 'info',
        ];

        return redirect()->route('article.all')->with($notification);
    }

    public function ArticleDelete($id)
    {
        $article = Article::findOrFail($id);

        $this->removeImageFile($article->image);
        $article->delete();

        $notification = [
            'message' => 'Artigo Eliminado Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('article.all')->with($notification);
    }

    private function storeImage(Request $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }

        $file = $request->file('image');
        $fileName = hexdec(uniqid()) . '.' . strtolower($file->getClientOriginalExtension());
        $destination = public_path('upload/article_images');

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $file->move($destination, $fileName);

        return 'upload/article_images/' . $fileName;
    }

    private function removeImageFile(?string $imagePath): void
    {
        if (empty($imagePath)) {
            return;
        }

        $fullPath = public_path($imagePath);

        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
}
