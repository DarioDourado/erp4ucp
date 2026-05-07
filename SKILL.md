# PROJECT SKILL — ERP4UCP

> Gerado por auditoria automática em 2026-04-25.  
> Atualiza este ficheiro sempre que um novo padrão for estabelecido pela equipa.

---

## 1. VISÃO GERAL

ERP4UCP é um sistema ERP (Enterprise Resource Planning) web em fase inicial de desenvolvimento, construído com Laravel 12. Inclui um painel de administração completo baseado no tema Upcube (Bootstrap 4 + jQuery), protegido por autenticação Laravel Breeze. Atualmente implementa as tabelas de dados primários do ERP: Fornecedores, Códigos Postais, Famílias de Produtos, Taxas de IVA e Unidades de Medida — todas com CRUD completo.

O sistema é puramente web (sem API REST). A interface de administração está totalmente em português europeu (PT).

---

## 2. TECH STACK

| Componente         | Tecnologia                        | Versão       |
|--------------------|-----------------------------------|--------------|
| Framework          | Laravel                           | ^12.0        |
| PHP                | PHP                               | ^8.2         |
| Autenticação       | Laravel Breeze                    | ^2.4 (dev)   |
| Base de dados      | SQLite (dev) / MySQL (prod)       | —            |
| ORM                | Eloquent (Laravel)                | —            |
| Migrations         | doctrine/dbal                     | ^4.4         |
| Frontend (admin)   | Upcube template (Bootstrap 4)     | —            |
| Frontend (auth)    | Tailwind CSS + Alpine.js (Breeze) | ^3.1 / ^3.4  |
| Build tool         | Vite + laravel-vite-plugin        | ^7.0 / ^2.0  |
| JS principal       | jQuery                            | (via assets) |
| Tabelas            | DataTables.net (Bootstrap 4)      | (via assets) |
| Dropdowns          | Select2                           | (via assets) |
| Validação JS       | jQuery Validate                   | (via assets) |
| Notificações       | Toastr.js                         | (via CDN)    |
| Confirmações       | SweetAlert2                       | ^10 (via CDN)|
| Testes             | Pest                              | ^3.8         |
| Formatação código  | Laravel Pint                      | ^1.24        |
| REPL               | Laravel Tinker                    | ^2.10.1      |

---

## 3. ESTRUTURA DE PASTAS ANOTADA

```
erp4ucp/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Actl/                  ← PASTA CUSTOM: todos os controllers do domínio ERP
│   │   │   │   ├── FamilyController.php
│   │   │   │   ├── PostalCodeController.php
│   │   │   │   ├── SupplierController.php
│   │   │   │   ├── TaxRateController.php
│   │   │   │   └── UnitMeasureController.php
│   │   │   ├── Auth/                  ← Controllers Breeze (não alterar)
│   │   │   ├── Teste/                 ← PASTA TEMPORÁRIA: controllers de teste/protótipo
│   │   │   │   └── Teste01Controller.php
│   │   │   ├── AdminController.php    ← Perfil, password e logout do admin
│   │   │   ├── Controller.php         ← Controller base
│   │   │   └── ProfileController.php  ← Perfil Breeze (não alterar)
│   │   ├── Middleware/
│   │   │   └── ValidaIdade.php        ← Custom middleware de exemplo
│   │   └── Requests/
│   │       ├── Auth/                  ← Form Requests Breeze (não alterar)
│   │       └── ProfileUpdateRequest.php
│   ├── Models/                        ← Todos os Eloquent Models
│   ├── Providers/
│   │   └── AppServiceProvider.php     ← Vazio por enquanto
│   └── View/
│       └── Components/               ← View Components Breeze (AppLayout, GuestLayout)
├── bootstrap/
│   └── app.php                        ← Registo de middlewares (Laravel 12 style)
├── config/                            ← Configurações padrão Laravel
├── database/
│   ├── factories/                     ← Apenas UserFactory (Breeze)
│   ├── migrations/                    ← Migrations do projeto
│   └── seeders/
│       └── DatabaseSeeder.php         ← Só cria User de teste
├── public/
│   ├── backend/
│   │   └── assets/                   ← Template Upcube: Bootstrap, jQuery, DataTables, etc.
│   ├── build/                         ← Assets compilados por Vite (Breeze)
│   ├── logotipos/                     ← Logótipos do projeto
│   └── upload/
│       └── admin_images/              ← Imagens de perfil dos utilizadores
├── resources/
│   ├── css/app.css                    ← CSS Tailwind (Breeze/auth)
│   ├── js/app.js                      ← JS Alpine.js (Breeze/auth)
│   └── views/
│       ├── admin/                     ← Layout mestre e partials do painel
│       │   ├── admin_master.blade.php ← LAYOUT PRINCIPAL do painel ERP
│       │   ├── body/
│       │   │   ├── header.blade.php
│       │   │   ├── sidebar.blade.php
│       │   │   └── footer.blade.php
│       │   ├── index.blade.php        ← Dashboard
│       │   ├── admin_profile_view.blade.php
│       │   ├── admin_profile_edit.blade.php
│       │   └── admin_change_password.blade.php
│       ├── auth/                      ← Views Breeze (login, register, etc.)
│       ├── backend/                   ← PASTA PRINCIPAL: todas as views de CRUD
│       │   ├── family/
│       │   ├── postalCode/
│       │   ├── supplier/
│       │   ├── taxRate/
│       │   └── unitMeasure/
│       ├── components/                ← Blade Components Breeze
│       ├── layouts/                   ← Layouts Breeze (app.blade.php, guest.blade.php)
│       └── profile/                  ← Perfil Breeze
├── routes/
│   ├── web.php                        ← Todas as rotas web do projeto
│   ├── auth.php                       ← Rotas Breeze (não alterar)
│   └── console.php                    ← Comandos Artisan agendados
└── Agent Skills/                      ← PASTA CUSTOM: ficheiros de skill para agentes IA
```

---

## 4. ARQUITETURA MVC — REGRAS DA EQUIPA

### 4.1 Models

**Regras:**
- Namespace: `App\Models`
- Naming: PascalCase singular — `Family`, `Supplier`, `PostalCode`
- **`$table` é SEMPRE definido explicitamente** — o nome da tabela é PascalCase (não o padrão Laravel de snake_case plural)
- **Usar `$guarded = []`** em todos os models de domínio (não `$fillable`) — excepção: `User` usa `$fillable`
- Incluir `use HasFactory;` em todos os models
- Sem `$casts` por enquanto (apenas o User tem casts para auth)
- Sem scopes locais
- Sem mutators/accessors
- Relações nomeadas com sufixo descritivo: `postalCodeLink()` (não apenas `postalCode()`)

**Exemplo canónico** (`app/Models/Supplier.php`):
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $table = "Supplier";
    protected $guarded = [];

    public function postalCodeLink()
    {
        return $this->belongsTo(PostalCode::class, 'postalCode', 'postalCode');
    }
}
```

**Model simples sem relações** (`app/Models/Family.php`):
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Family extends Model
{
    use HasFactory;

    protected $table = "Family";
    protected $guarded = [];
}
```

---

### 4.2 Controllers

**Regras gerais:**
- Todos os controllers de domínio ERP vivem em `App\Http\Controllers\Actl\`
- Namespace: `App\Http\Controllers\Actl`
- Naming: `{EntidadePascalCase}Controller` — ex: `SupplierController`, `FamilyController`
- **Os métodos têm nomes PascalCase no formato `{Entidade}{Ação}`** — ex: `SupplierAll`, `SupplierStore`, `SupplierEdit`
- Nenhum constructor — sem injeção de dependências
- **Validação inline** com `$request->validate([...])` — não usar Form Requests para controllers Actl
- **Inserção via `Model::insert([...])`** — não `Model::create()`
- **Sempre incluir** `created_by`/`updated_by` (via `Auth::user()->id`) e `created_at`/`updated_at` (via `Carbon::now()`) no insert/update
- **Padrão de notificação**: array `$notification` com chaves `message` e `alert-type`, passado ao redirect via `->with($notification)`
- **`alert-type` padrão**: `'success'` para adicionar e eliminar; `'info'` para atualizar
- Redirecionar sempre para `{entity}.all` após store/update/delete
- Usar `findOrFail($id)` para edit/update/delete — nunca `find($id)`
- Para update: o `$id` vem como campo hidden no form (`$request->id`)

**Exemplo canónico** (`app/Http/Controllers/Actl/FamilyController.php`):
```php
<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Family;
use Illuminate\Support\Facades\Auth;
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
            'created_by' => Auth::user()->id,
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
            'updated_by' => Auth::user()->id,
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
```

**Import order padrão nos controllers Actl:**
```php
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{NomeDaEntidade};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
```

---

### 4.3 Rotas

**Regras:**
- Ficheiro: `routes/web.php`
- Todas as rotas ERP agrupadas em `Route::middleware(['auth'])->group(...)`
- Cada entidade agrupada com `Route::controller(XyzController::class)->group(...)`
- **Naming convention das rotas**: `{entity}.{action}` em camelCase minúsculo — ex: `postalCode.all`, `supplier.store`
- **Actions disponíveis**: `all`, `add`, `store`, `edit`, `update`, `delete`
- **HTTP methods**: `GET` para all/add/edit/delete; `POST` para store/update
- **URL pattern**: `/{entity}/{action}` ou `/{entity}/{action}/{id}`
- **Sem Route Model Binding** — o `{id}` é passado manualmente
- Os métodos referenciados nas rotas usam PascalCase: `'SupplierAll'`, `'SupplierStore'`

**Exemplo canónico** (de `routes/web.php`):
```php
// TaxRate
Route::controller(TaxRateController::class)->group(function () {
    Route::get('/taxRate/all',         'TaxRateAll')    ->name('taxRate.all');
    Route::get('/taxRate/add',         'TaxRateAdd')    ->name('taxRate.add');
    Route::post('/taxRate/store',      'TaxRateStore')  ->name('taxRate.store');
    Route::get('/taxRate/edit/{id}',   'TaxRateEdit')   ->name('taxRate.edit');
    Route::post('/taxRate/update',     'TaxRateUpdate') ->name('taxRate.update');
    Route::get('/taxRate/delete/{id}', 'TaxRateDelete') ->name('taxRate.delete');
});
```

**Estrutura completa do web.php:**
```php
// ROTAS DO ERP4U
Route::middleware(['auth'])->group(function () {

    Route::controller(NovaEntidadeController::class)->group(function () {
        Route::get('/novaEntidade/all',         'NovaEntidadeAll')    ->name('novaEntidade.all');
        Route::get('/novaEntidade/add',         'NovaEntidadeAdd')    ->name('novaEntidade.add');
        Route::post('/novaEntidade/store',      'NovaEntidadeStore')  ->name('novaEntidade.store');
        Route::get('/novaEntidade/edit/{id}',   'NovaEntidadeEdit')   ->name('novaEntidade.edit');
        Route::post('/novaEntidade/update',     'NovaEntidadeUpdate') ->name('novaEntidade.update');
        Route::get('/novaEntidade/delete/{id}', 'NovaEntidadeDelete') ->name('novaEntidade.delete');
    });

});
```

**Adicionar o import no topo de web.php:**
```php
use App\Http\Controllers\Actl\NovaEntidadeController;
```

---

### 4.4 Views Blade

**Regras:**
- Todas as views do painel ERP estendem `admin.admin_master`
- O section name é **sempre** `admin`: `@section('admin') ... @endsection`
- **Estrutura de pastas**: `resources/views/backend/{entidade}/`
- **Naming das views**: `{entidade}_{acao}.blade.php` (snake_case, underscore entre entidade e ação)
  - Ex: `supplier_all.blade.php`, `supplier_add.blade.php`, `supplier_edit.blade.php`
- **Entidade na pasta**: camelCase na pasta — ex: `postalCode/`, `taxRate/`, `unitMeasure/`
- Formulários têm sempre `id="myForm"` e `@csrf`
- Campos de formulário têm sempre `id` e `name` iguais ao nome da coluna na BD
- Usar `{{ old('campo', $entity->campo) }}` nos campos de edição
- O `id` do registo para update vem como `<input type="hidden" name="id">`
- **DataTables**: todas as views `_all` usam `<table id="datatable">` com `DataTable()` no script
- **jQuery Validate**: todas as views de formulário usam `$('#myForm').validate({...})`
- **Scripts específicos da página**: usar `@push('scripts') ... @endpush`
- **Scripts DataTables**: usar `@push('datatable-scripts') @endpush` no fim das views `_all`
- Erros de validação: `@error('campo') <span class="invalid-feedback d-block">{{ $message }}</span> @enderror`
- Classe de erro no input: `@error('campo') is-invalid @enderror` (opcional, o Validate JS também adiciona)

**View canónica — lista** (`resources/views/backend/family/family_all.blade.php`):
```blade
@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Famílias de Produtos</h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <a href="{{ route('family.add') }}"
                           class="btn btn-secondary btn-rounded waves-effect waves-light"
                           style="float: right;">
                            Adicionar Família
                        </a>

                        <br><br>

                        <h4 class="card-title">Family Products All Data</h4>

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Family</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($families as $key => $item)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>{{ $item->family }}</td>
                                        <td>
                                            <a href="{{ route('family.edit', $item->id) }}"
                                               class="btn btn-info btn-sm" title="Edit Data">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="{{ route('family.delete', $item->id) }}"
                                               class="btn btn-danger btn-sm delete-btn" title="Delete Data">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    $(document).ready(function () {
        if ($('#datatable').length) {
            $('#datatable').DataTable({
                responsive: true,
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                lengthChange: true,
                autoWidth: false
            });
        }
    });
</script>

@endsection

@push('datatable-scripts')
@endpush
```

**View canónica — formulário add** (`resources/views/backend/family/family_add.blade.php`):
```blade
@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Adicionar Família de Produto</h4>
                        <br><br>

                        <form method="post" action="{{ route('family.store') }}" id="myForm">
                            @csrf

                            <div class="row mb-3">
                                <label for="family" class="col-sm-2 col-form-label">Família</label>
                                <div class="form-group col-sm-10">
                                    <input id="family" name="family" class="form-control" type="text" autofocus>
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light" value="Adicionar Família">
                        </form>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script type="text/javascript">
    $(document).ready(function () {
        $('#myForm').validate({
            rules: {
                family: { required: true }
            },
            messages: {
                family: { required: 'A Família é obrigatória.' }
            },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element) {
                $(element).addClass('is-invalid');
            },
            unhighlight: function (element) {
                $(element).removeClass('is-invalid');
            }
        });
    });
</script>
@endpush

@endsection
```

**View canónica — formulário edit** (estrutura, baseada em `postalCode_edit.blade.php`):
```blade
@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <h4 class="card-title">Editar {Entidade}</h4>
                        <br><br>

                        <form method="post" action="{{ route('{entity}.update') }}" id="myForm">
                            @csrf
                            <input type="hidden" name="id" value="{{ ${entity}->id }}">

                            <div class="row mb-3">
                                <label for="campo" class="col-sm-2 col-form-label">Label</label>
                                <div class="form-group col-sm-10">
                                    <input id="campo" name="campo" class="form-control"
                                           value="{{ old('campo', ${entity}->campo) }}" type="text">
                                    @error('campo')
                                        <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <input type="submit" class="btn btn-info waves-effect waves-light"
                                   value="Atualizar {Entidade}">
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script type="text/javascript">
    $(document).ready(function () {
        $('#myForm').validate({
            rules: { campo: { required: true } },
            messages: { campo: { required: 'O campo é obrigatório.' } },
            errorElement: 'span',
            errorPlacement: function (error, element) {
                error.addClass('invalid-feedback');
                element.closest('.form-group').append(error);
            },
            highlight: function (element) { $(element).addClass('is-invalid'); },
            unhighlight: function (element) { $(element).removeClass('is-invalid'); }
        });
    });
</script>
@endpush

@endsection
```

**Select com Select2** (para campos de relação, como em `supplier_add.blade.php`):
```blade
<div class="row mb-3">
    <label for="postalCode" class="col-sm-2 col-form-label">Código Postal</label>
    <div class="form-group col-sm-10">
        <select id="postalCode" name="postalCode" class="form-control select2">
            <option value="">Selecionar ...</option>
            @foreach($postalCodes as $pc)
                <option value="{{ $pc->postalCode }}"
                    {{ old('postalCode') == $pc->postalCode ? 'selected' : '' }}>
                    {{ $pc->postalCode }} – {{ $pc->location }}
                </option>
            @endforeach
        </select>
    </div>
</div>
```

```javascript
// Inicialização Select2 no $(document).ready():
$('.select2').select2({
    placeholder: 'Selecione ...',
    allowClear: true,
    width: '100%'
});
```

---

### 4.5 Form Requests

Form Requests **não são usados** para os controllers de domínio Actl. A validação é sempre inline com `$request->validate([...])`.

Form Requests existem apenas para autenticação (Breeze) e perfil:
- `app/Http/Requests/Auth/LoginRequest.php` — Breeze (não alterar)
- `app/Http/Requests/ProfileUpdateRequest.php` — perfil

Se no futuro a equipa decidir adotar Form Requests para os controllers Actl, usar o naming `Store{Entidade}Request` / `Update{Entidade}Request`.

---

### 4.6 API Resources

**Não existem.** Este projeto não tem API REST. Não criar Resources sem decisão explícita da equipa.

---

### 4.7 Middleware Custom

**Regras:**
- Vivem em `app/Http/Middleware/`
- Naming: PascalCase descritivo — ex: `ValidaIdade`
- Registados em `bootstrap/app.php` com um alias em snake_case ou kebab-case

**Exemplo de registo** (`bootstrap/app.php`):
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'valida_idade' => ValidaIdade::class,
    ]);
})
```

**Uso nas rotas:**
```php
Route::post('/rota', 'Metodo')->middleware('valida_idade');
```

---

## 5. BASE DE DADOS

### 5.1 Convenções de Migration

**Regras:**
- **Nomes de tabelas são PascalCase** — `Supplier`, `Family`, `PostalCode`, `TaxRate`, `UnitMeasure` (diverge do padrão Laravel de snake_case plural)
- **Naming de ficheiros de migration**: `YYYY_MM_DD_HHMMSS_create_{entidade_lower}_table.php` (o nome do ficheiro é lowercase snake, mas a tabela dentro é PascalCase)
- Usar `if (!Schema::hasTable('NomeTabela'))` para tabelas críticas
- **Colunas padrão de auditoria**: incluir sempre `created_by` e `updated_by` como `integer()->nullable()`
- **Status**: incluir `tinyInteger('status')->default(1)` em todas as tabelas de domínio
- **Timestamps**: sempre `$table->timestamps()` — nunca `softDeletes()`
- **Foreign keys**: não usar `foreignId()->constrained()` — fazer referência manual por nome de coluna correspondente
- Usar `Schema::table()` para migrations de alteração; incluir `down()` com o inverso

**Migration canónica** (`database/migrations/2026_04_19_100002_create_family_table.php`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Family', function (Blueprint $table) {
            $table->id();
            $table->string('family', 25)->unique();
            $table->tinyInteger('status')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Family');
    }
};
```

**Migration de alteração canónica** (`database/migrations/2026_04_19_163000_make_nif_nullable_in_supplier_table.php`):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Supplier', function (Blueprint $table) {
            $table->string('nif', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('Supplier', function (Blueprint $table) {
            $table->string('nif', 20)->nullable(false)->change();
        });
    }
};
```

**Tipos de colunas mais usados:**
- `$table->id()` — PK auto-increment
- `$table->integer('codigo')->unique()` — códigos de negócio únicos
- `$table->string('campo', 25)` — strings curtas com limite explícito
- `$table->string('campo')` — strings sem limite (default 255)
- `$table->double('taxRate')` — valores percentuais/decimais
- `$table->tinyInteger('status')->default(1)` — flag ativo/inativo
- `$table->integer('created_by')->nullable()` — auditoria
- `$table->timestamps()` — created_at + updated_at

### 5.2 Convenções Eloquent

**Criar registos — usar sempre `Model::insert()`:**
```php
Family::insert([
    'family'     => $request->family,
    'created_by' => Auth::user()->id,
    'created_at' => Carbon::now(),
]);
```

**Atualizar — usar `findOrFail()->update()`:**
```php
Family::findOrFail($family_id)->update([
    'family'     => $request->family,
    'updated_by' => Auth::user()->id,
    'updated_at' => Carbon::now(),
]);
```

**Listar — usar `latest()->get()`:**
```php
$families = Family::latest()->get();
```

**Buscar para edição — usar `findOrFail()`:**
```php
$family = Family::findOrFail($id);
```

**Eliminar:**
```php
Family::findOrFail($id)->delete();
```

---

## 6. AUTORIZAÇÃO

**Não existe sistema de autorização** (Policies, Gates) implementado no projeto. Toda a proteção é feita exclusivamente por middleware de autenticação (`auth`).

Quando for implementado, seguir o padrão Laravel de Policies com `AuthServiceProvider`.

---

## 7. AUTENTICAÇÃO

- **Sistema**: Laravel Breeze (scaffold completo já aplicado)
- **Guard**: `web` (padrão)
- **Redirecção após login**: `/dashboard` (configurado em `RouteServiceProvider` via Breeze)
- **Logout custom**: `GET /admin/logout` → `AdminController@destroy` (usa `Auth::guard('web')->logout()`)
- **Rotas protegidas**: todas as rotas ERP têm `middleware(['auth'])`
- **Dashboard**: `middleware(['auth', 'verified'])`
- **Rotas auth**: definidas em `routes/auth.php` (não alterar — gerado pelo Breeze)

---

## 8. SISTEMA DE NOTIFICAÇÕES (TOASTR)

Este projeto usa o Toastr.js para notificações flash. O sistema está configurado no `admin_master.blade.php`.

**Padrão de notificação nos controllers Actl:**
```php
$notification = [
    'message'    => 'Mensagem em português europeu.',
    'alert-type' => 'success',   // success | info | warning | error
];
return redirect()->route('entity.all')->with($notification);
```

**Regra de `alert-type`:**
- `'success'` — ao adicionar um registo ou eliminá-lo com sucesso
- `'info'` — ao atualizar um registo
- `'warning'` — avisos (uso futuro)
- `'error'` — erros (uso futuro)

**Como funciona no layout** (`admin_master.blade.php`):
```javascript
@if(Session::has('message'))
    var type = "{{ Session::get('alert-type','info') }}";
    switch(type){
        case 'success': toastr.success("{{ Session::get('message') }}"); break;
        case 'info':    toastr.info("{{ Session::get('message') }}");    break;
        case 'warning': toastr.warning("{{ Session::get('message') }}"); break;
        case 'error':   toastr.error("{{ Session::get('message') }}");   break;
    }
@endif
```

---

## 9. NAMING CONVENTIONS

| Elemento               | Correto ✅                                      | Errado ❌                          |
|------------------------|------------------------------------------------|------------------------------------|
| Model                  | `Supplier`                                     | `Suppliers`                        |
| Nome da tabela (BD)    | `"Supplier"` (PascalCase)                      | `"suppliers"` (snake plural)       |
| Controller domínio     | `Actl\SupplierController`                      | `SupplierController` (raiz)        |
| Método controller      | `SupplierAll()`, `SupplierStore()`             | `index()`, `store()`               |
| Rota nomeada           | `supplier.all`, `supplier.store`               | `suppliers.index`, `getSuppliers`  |
| URL de rota            | `/supplier/all`, `/supplier/store`             | `/suppliers`, `/supplier/create`   |
| View pasta             | `backend/supplier/`                            | `supplier/`, `suppliers/`          |
| View ficheiro          | `supplier_all.blade.php`                       | `index.blade.php`, `supplierAll`   |
| Migration ficheiro     | `create_supplier_table.php`                    | `create_suppliers_table.php`       |
| Migration tabela       | `Schema::create('Supplier', ...)`             | `Schema::create('suppliers', ...)`|
| Variável na view (list)| `$suppliers` / `$families`                     | `$data`, `$items`                  |
| Variável na view (edit)| `$supplier`, `$family`                         | `$data`, `$item`                   |
| Campo de auditoria     | `created_by`, `updated_by`                     | `createdBy`, `created_user_id`     |
| Notificação session    | `message` + `alert-type`                       | `success`, `msg`                   |
| Middleware alias       | `valida_idade` (snake_case)                    | `validaIdade`, `ValidaIdade`       |

---

## 10. PADRÕES DE CÓDIGO

### Linguagem
- **Código (classes, métodos, variáveis, rotas)**: inglês
- **Mensagens ao utilizador (notificações, labels, títulos)**: português europeu (PT-PT)
- Usar "Corretamente" (não "Correctamente"); "Atualizado" (não "Actualizado" — embora o projeto use ambas as formas, preferir sem "c")

### Estrutura de um Controller Actl típico

```php
<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NovaEntidade;           // Model principal
use App\Models\OutraEntidade;          // Outros models necessários (ex: para selects)
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class NovaEntidadeController extends Controller
{
    public function NovaEntidadeAll()
    {
        $novaEntidades = NovaEntidade::latest()->get();
        return view('backend.novaEntidade.novaEntidade_all', compact('novaEntidades'));
    }

    public function NovaEntidadeAdd()
    {
        // Se tiver selects, carregar dados aqui:
        // $outrasEntidades = OutraEntidade::latest()->get();
        return view('backend.novaEntidade.novaEntidade_add'/*, compact('outrasEntidades')*/);
    }

    public function NovaEntidadeStore(Request $request)
    {
        $request->validate([
            'campo1' => 'required|unique:NovaEntidade,campo1',
            'campo2' => 'required',
        ]);

        NovaEntidade::insert([
            'campo1'     => $request->campo1,
            'campo2'     => $request->campo2,
            'created_by' => Auth::user()->id,
            'created_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'NovaEntidade Adicionada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('novaEntidade.all')->with($notification);
    }

    public function NovaEntidadeEdit($id)
    {
        $novaEntidade = NovaEntidade::findOrFail($id);
        return view('backend.novaEntidade.novaEntidade_edit', compact('novaEntidade'));
    }

    public function NovaEntidadeUpdate(Request $request)
    {
        $novaEntidade_id = $request->id;

        NovaEntidade::findOrFail($novaEntidade_id)->update([
            'campo1'     => $request->campo1,
            'campo2'     => $request->campo2,
            'updated_by' => Auth::user()->id,
            'updated_at' => Carbon::now(),
        ]);

        $notification = [
            'message'    => 'NovaEntidade Actualizada Corretamente.',
            'alert-type' => 'info',
        ];

        return redirect()->route('novaEntidade.all')->with($notification);
    }

    public function NovaEntidadeDelete($id)
    {
        NovaEntidade::findOrFail($id)->delete();

        $notification = [
            'message'    => 'NovaEntidade Eliminada Corretamente.',
            'alert-type' => 'success',
        ];

        return redirect()->route('novaEntidade.all')->with($notification);
    }
}
```

### Estrutura de um Model típico

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NovaEntidade extends Model
{
    use HasFactory;

    protected $table = "NovaEntidade";   // PascalCase — obrigatório
    protected $guarded = [];              // Não usar $fillable nos models de domínio
}
```

---

## 11. FRONTEND NAS VIEWS

### Duas stacks paralelas — não misturar:

**Stack 1 — Painel ERP** (views em `resources/views/backend/` e `admin/`):
- CSS/JS: `public/backend/assets/` (template Upcube, carregado estaticamente no `admin_master.blade.php`)
- **Bootstrap 4** para layout e componentes
- **jQuery** como base JS
- **DataTables.net** para tabelas (`#datatable`)
- **Select2** para dropdowns com pesquisa (`.select2`)
- **jQuery Validate** para validação client-side (`$('#myForm').validate({...})`)
- **Toastr.js** para notificações flash (via CDN)
- **SweetAlert2** para confirmações de eliminação (via CDN, `sweetalert2.js` local)
- Ícones: **Remix Icons** (`ri-*`) + **Font Awesome** (`fas fa-*`) + **MDI** (`mdi mdi-*`)

**Stack 2 — Auth/Breeze** (views em `resources/views/auth/`, `layouts/`, `profile/`):
- CSS: Tailwind CSS (compilado por Vite → `public/build/`)
- JS: Alpine.js
- Referenciado com `@vite(['resources/css/app.css', 'resources/js/app.js'])`

### Assets do template Upcube — referência:
```blade
{{-- CSS --}}
asset('backend/assets/css/bootstrap.min.css')
asset('backend/assets/css/icons.min.css')
asset('backend/assets/css/app.min.css')

{{-- JS --}}
asset('backend/assets/libs/jquery/jquery.min.js')
asset('backend/assets/libs/bootstrap/js/bootstrap.bundle.min.js')
asset('backend/assets/libs/datatables.net/js/jquery.dataTables.min.js')
asset('backend/assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js')
```

### @stack disponíveis no admin_master:
- `@stack('scripts')` — JS no final do body (antes dos outros stacks)
- `@stack('datatable-scripts')` — após scripts, para DataTables extras

### Uploads de ficheiros (imagens de perfil):
- Destino: `public/upload/admin_images/`
- Filename: `date('YmdHi') . $file->getClientOriginalName()`
- Referência: `url('upload/admin_images/' . $user->profile_image)`
- Imagem padrão: `url('upload/no_image.jpg')`

---

## 12. ANTI-PATTERNS — NUNCA FAÇAS ISTO

1. **Nunca uses `Model::create()` nos controllers Actl** — usar sempre `Model::insert([...])`
2. **Nunca uses `$fillable` nos models de domínio** — usar `$guarded = []`
3. **Nunca coloques tabelas em snake_case plural** — todas as tabelas de domínio são PascalCase: `"Supplier"`, não `"suppliers"`
4. **Nunca coloques controllers Actl na raiz de Controllers/** — pertencem sempre a `App\Http\Controllers\Actl\`
5. **Nunca uses nomes de método em camelCase estilo Laravel (`index`, `store`, `show`)** — usar `{Entidade}{Ação}`: `SupplierAll`, `SupplierStore`
6. **Nunca uses `Route::resource()`** — as rotas são definidas manualmente com o padrão estabelecido
7. **Nunca uses `response()->json()`** — não há API REST neste projeto
8. **Nunca mistures o stack Bootstrap/jQuery com Tailwind/Alpine nas views de backend** — cada stack tem o seu contexto
9. **Nunca deixes de incluir `created_by`/`updated_by` e `Carbon::now()`** nos inserts/updates dos controllers Actl
10. **Nunca uses `$request->all()` para insert** — listar explicitamente os campos
11. **Nunca escreças `array()` para notificações** — usar sempre `[]` (square brackets)
12. **Nunca uses `find()` sem `OrFail`** — sempre `findOrFail($id)` nos controllers
13. **Nunca omitas `status` e auditoria numa nova tabela de domínio** — são colunas padrão de todas as tabelas ERP

---

## 13. FICHEIROS DE REFERÊNCIA CANÓNICA

| Tipo                        | Ficheiro                                                              |
|-----------------------------|-----------------------------------------------------------------------|
| Model simples               | `app/Models/Family.php`                                               |
| Model com relação           | `app/Models/Supplier.php`                                             |
| Controller domínio completo | `app/Http/Controllers/Actl/FamilyController.php`                      |
| Controller com relações     | `app/Http/Controllers/Actl/SupplierController.php`                    |
| Rotas padrão                | `routes/web.php` (secção `// Family`)                                 |
| View — lista                | `resources/views/backend/family/family_all.blade.php`                 |
| View — add simples          | `resources/views/backend/family/family_add.blade.php`                 |
| View — add com select       | `resources/views/backend/supplier/supplier_add.blade.php`             |
| View — edit                 | `resources/views/backend/postalCode/postalCode_edit.blade.php`        |
| Layout principal            | `resources/views/admin/admin_master.blade.php`                        |
| Sidebar                     | `resources/views/admin/body/sidebar.blade.php`                        |
| Migration simples           | `database/migrations/2026_04_19_100002_create_family_table.php`       |
| Migration completa          | `database/migrations/2026_03_20_093907_create_postal_codes_table.php` |
| Migration de alteração      | `database/migrations/2026_04_19_163000_make_nif_nullable_in_supplier_table.php` |
| Middleware custom           | `app/Http/Middleware/ValidaIdade.php`                                 |
| Registo middleware          | `bootstrap/app.php`                                                   |

---

## 14. CHECKLIST ANTES DE CRIAR QUALQUER FICHEIRO NOVO

### Para um CRUD completo de nova entidade:

- [ ] **Model** criado em `app/Models/` com `$table = "NomeEntidade"` (PascalCase) e `$guarded = []`?
- [ ] **Migration** criada com nome `YYYY_MM_DD_HHMMSS_create_{entidade_lower}_table.php`?
- [ ] A tabela na migration inclui `status`, `created_by`, `updated_by`, `timestamps()`?
- [ ] **Controller** criado em `app/Http/Controllers/Actl/` com namespace correto?
- [ ] Os 5 métodos do controller têm nomes `{Entidade}All/Add/Store/Edit/Update/Delete`?
- [ ] O `Store` usa `Model::insert([...])` com `created_by` e `Carbon::now()`?
- [ ] O `Update` usa `findOrFail()->update([...])` com `updated_by` e `Carbon::now()`?
- [ ] O `Delete` usa `findOrFail($id)->delete()`?
- [ ] Todas as ações terminam com `$notification = [...]` e `redirect()->route('entity.all')`?
- [ ] **6 rotas** definidas em `web.php` dentro do grupo `middleware(['auth'])`?
- [ ] As rotas seguem o padrão `{entity}.all/add/store/edit/update/delete`?
- [ ] O import do controller foi adicionado ao topo de `web.php`?
- [ ] **3 views** criadas em `resources/views/backend/{entity}/`: `_all`, `_add`, `_edit`?
- [ ] Todas as views têm `@extends('admin.admin_master')` e `@section('admin')`?
- [ ] A view `_all` tem `<table id="datatable">` com `DataTable()` no script?
- [ ] As views de formulário têm `id="myForm"`, `@csrf`, e `$('#myForm').validate({...})`?
- [ ] O campo hidden `<input type="hidden" name="id">` está na view `_edit`?
- [ ] A entidade foi adicionada ao **sidebar** em `admin/body/sidebar.blade.php`?
- [ ] A migration foi executada com `php artisan migrate`?

---

## 15. COMO ADICIONAR UMA NOVA ENTIDADE AO SIDEBAR

Após criar o CRUD, adicionar ao sidebar (`resources/views/admin/body/sidebar.blade.php`) na secção "Tabelas Primárias":

```blade
<li><a href="{{ route('novaEntidade.all') }}">Nome da Entidade</a></li>
```
