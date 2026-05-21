# OCR Improvements Plan

## Problemas Identificados (após análise do código atual)

### 1. PHP `parsePurchaseOrderDocument()` — Fornecedor errado (Falha #1)

**Ficheiro:** `app/Http/Controllers/Actl/PurchaseOrderController.php:1106-1136`

O parser atual procura a primeira linha que não seja cabeçalho/endereço/NIF. Para `encomenda_1.png`, o Tesseract extrai:

```
Na Mercearia (Qnnuaçãs Hora 24 ENCOMENDA Nº 2025/058
```

Esta linha **não começa** com "encomenda" (começa com "Na"), portanto não é detectada como cabeçalho → torna-se o nome do fornecedor.

**Solução:** Adicionar procura pelo label `FORNECEDOR:` antes da heurística da primeira linha.

### 2. PHP `extractPOTabularLines()` — Símbolo € bloqueia preço (Falha #5)

**Ficheiro:** `PurchaseOrderController.php:1211`

O `€` está colado ao preço (`1,15€`). O clean-up atual só remove `€` do **último** número da linha (`23,00 €`), mas o preço unitário `1,15€` fica com `€` e o regex do preço (`\d+(?:[.,]\d+)?`) não o captura.

**Solução:** `str_replace('€', '', $line)` no início do parsing de cada linha, e refatorar a lógica de extração de números para ser mais robusta.

### 3. PHP `parsePurchaseOrderDocument()` — Data não extraída (Falha #3)

**Ficheiro:** `PurchaseOrderController.php:1068-1148`

Não existe qualquer parsing da data da encomenda (`pODate`), apesar do texto conter `"DATA DA ENCOMENDA: 20/05/2025"`.

**Solução:** Adicionar regex `DATA\s+DA\s+ENCOMENDA:\s*(\d{2})[\/\-](\d{2})[\/\-](\d{4})`.

### 4. Python `ocr_processor.py` — PSM mode não otimizado para tabelas

**Ficheiro:** `ocr-service/ocr_processor.py:402`

Usa `--psm 4` (assume single column of text). Para documentos tabulares, `--psm 6` (assume uniform block of text) ou `--psm 11` (sparse text) podem dar melhores resultados.

**Solução:** Experimentar múltiplos PSM modes e escolher o melhor resultado.

### 5. Python `document_analyzer.py` — Modelo Ollama pequeno

**Ficheiro:** `ocr-service/document_analyzer.py:23`

Usa `qwen2.5:7b` (ou `qwen2.5:3b`). Modelos maiores como `qwen2.5:14b` ou `llama3.1:8b` dariam melhor compreensão de documentos em português.

### 6. Frontend — Sem edição dos dados extraídos

**Ficheiro:** `resources/views/backend/purchaseOrder/purchaseOrder_ocr.blade.php:303-416`

O utilizador não pode corrigir dados OCR antes de criar a encomenda.

---

## Implementação das Correções

### Fix 1: PHP `parsePurchaseOrderDocument()` — FORNECEDOR label + Data

Em `PurchaseOrderController.php:1106`, ANTES do loop de heurística da primeira linha, adicionar:

```php
// Procura label FORNECEDOR: (presente no documento do utilizador)
foreach ($lines as $line) {
    if (preg_match('/\bFORNECEDOR:\s*(.+)/i', $line, $m)) {
        $nome = trim($m[1]);
        // Limpa conteúdo misto na mesma linha
        $nome = preg_replace('/\s+\d{2}\/\d{2}\/\d{4}.*$/', '', $nome);
        $nome = preg_replace('/\s+NIF:.*$/i', '', $nome);
        $nome = preg_replace('/\s+CONTACTO:.*$/i', '', $nome);
        $supplier['nome'] = trim($nome);
        break;
    }
}
```

E após o NIF, adicionar data:

```php
// Data da encomenda
foreach ($lines as $line) {
    if (preg_match('/DATA\s+DA\s+ENCOMENDA:\s*(\d{2})[\/\-](\d{2})[\/\-](\d{4})/i', $line, $m)) {
        $supplier['dataEncomenda'] = "{$m[3]}-{$m[2]}-{$m[1]}";
        break;
    }
}
```

### Fix 2: PHP `extractPOTabularLines()` — € cleanup + números flexíveis

Substituir o início do Passo 2 por:

```php
// Remove € de toda a linha ANTES de qualquer parsing
$lineNoEuro = str_replace('€', '', $line);
$cleanedLine = $lineNoEuro;
// ... restante cleanup ...

// Extrai TODOS os números do final
preg_match_all('/(\d+(?:[.,]\d+)?)/u', $cleanedLine, $allNumbers);
$numbers = $allNumbers[1];

if (count($numbers) >= 2 && !$this->isLineExcluded($line)) {
    $last = $this->normalizeNumber(end($numbers));
    $secondLast = $this->normalizeNumber(prev($numbers));

    if (count($numbers) >= 4) {
        // 4+ números: qtd_encomendada qtd_confirmada preco_unit total
        $qtyStr = $numbers[count($numbers) - 3];
        $priceStr = $numbers[count($numbers) - 2];
    } elseif (count($numbers) >= 3 && $secondLast > 0 && $last / $secondLast > 1.5) {
        // qtd preco total
        $qtyStr = $numbers[count($numbers) - 3];
        $priceStr = $numbers[count($numbers) - 2];
    } else {
        // qtd preco
        $qtyStr = $numbers[count($numbers) - 2];
        $priceStr = $numbers[count($numbers) - 1];
    }
    // ... resto do código ...
}
```

### Fix 3: Python PSM mode

Em `ocr-service/ocr_processor.py`, adicionar:

```python
def extract_text_with_layout(image_path, psm_modes=[4, 6, 11]):
    best_text = ""
    best_lines = []
    for psm in psm_modes:
        custom_config = f'--oem 3 --psm {psm} -l por+eng'
        text = pytesseract.image_to_string(processed, config=custom_config)
        if len(text.strip()) > len(best_text.strip()):
            best_text = text
            best_lines = lines_from_psm(processed, psm)
    return {'raw_text': best_text.strip(), 'lines': best_lines, ...}
```

### Fix 4: Frontend — Edição de dados extraídos

Adicionar um botão "Editar dados extraídos" e campos editáveis na secção de resultados:

```html
<div id="extracted-data" class="editable-ocr-data">
    <!-- mostrar dados extraídos em campos editáveis -->
</div>
<button id="edit-ocr-data" class="btn btn-outline-primary btn-sm">
    <i class="fas fa-edit"></i> Editar dados
</button>
```

---

## Dependências entre correções

| Fix | Prioridade | Impacto | Depende de |
|-----|-----------|---------|-----------|
| 1 (Fornecedor) | **Crítica** | Bloqueia criação da encomenda | — |
| 2 (€/preços) | **Crítica** | Preços ficam a 0 | — |
| 3 (Data) | Alta | Melhoria, campo existente na BD | — |
| 4 (PSM) | Média | Melhora qualidade OCR base | — |
| 5 (LLM) | Baixa | Já funciona, melhoria incremental | — |
| 6 (Frontend) | Média | UX, permite correção manual | 1, 2 |

---

## Testes

1. Upload de `encomenda_1.png` e `encomenda_2.jpg`
2. Verificar no log: `[PO-OCR] Documento interpretado.` com supplier correto e linhas com preços
3. Verificar se a data aparece no resultado
4. Clicar "Criar Encomenda" e confirmar que o formulário aparece com os dados preenchidos
