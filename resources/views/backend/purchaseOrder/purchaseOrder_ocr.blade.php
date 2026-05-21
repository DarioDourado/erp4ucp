@extends('admin.admin_master')

@section('admin')
<div class="page-content" id="purchaseOrderOcrPage">
    <div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0">Encomenda a Fornecedor com OCR</h4>
            </div>
            <p class="text-muted mb-4">
                Tire uma foto ao documento do fornecedor ou carregue uma imagem/PDF para extrair automaticamente
                os dados e criar uma nova encomenda.
            </p>
        </div>
    </div>

    <div class="row">
        <!-- Secção 1: Câmara e Upload -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="card-title mb-0">1. Capturar Documento</h4>
                    </div>

                    <div class="alert alert-light border py-2 mb-3" role="alert">
                        Pode usar a câmara ou carregar um ficheiro (PDF/JPEG/PNG, até 10MB).
                    </div>

                    <!-- Câmara -->
                    <div id="camera-section" class="mb-3">
                        <label class="form-label">Câmara</label>
                        <div class="position-relative camera-wrapper">
                            <video id="camera-preview"
                                   width="100%"
                                   style="display: block; background: #000; min-height: 300px;">
                            </video>
                            <div id="camera-loading" class="position-absolute top-50 start-50 translate-middle text-white"
                                 style="display: none;">
                                <div class="spinner-border spinner-border-sm" role="status">
                                    <span class="visually-hidden">A carregar câmara...</span>
                                </div>
                                <p class="small mt-2 mb-0">A iniciar câmara...</p>
                            </div>
                        </div>
                        <button type="button" id="capture-btn" class="btn btn-primary btn-sm w-100 mt-2"
                                title="Tirar fotografia">
                            <i class="fas fa-camera"></i> Tirar Foto
                        </button>
                        <button type="button" id="toggle-camera-btn" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <i class="fas fa-times"></i> Fechar Câmara
                        </button>
                    </div>

                    <hr>

                    <!-- Upload de Arquivo -->
                    <div id="upload-section">
                        <label class="form-label">Carregar Documento</label>
                        <div class="input-group">
                            <input type="file"
                                   id="document-input"
                                   accept="image/*,.pdf"
                                   class="form-control"
                                   aria-label="Selecionar documento">
                            <label class="input-group-text" for="document-input">
                                <i class="fas fa-upload"></i>
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Tipos suportados: PDF, JPEG, PNG (máx. 10MB)
                        </small>
                    </div>

                    <hr>

                    <!-- Canvas para captura (oculto) -->
                    <canvas id="capture-canvas" style="display: none;"></canvas>

                    <!-- Botão de Processar -->
                    <button type="button"
                            id="process-btn"
                            class="btn btn-primary w-100 mt-3"
                            disabled>
                        <i class="fas fa-magic"></i> Processar com OCR
                    </button>

                    <!-- Indicador de Processamento -->
                    <div id="processing-indicator" class="text-center mt-3" style="display: none;">
                        <div class="spinner-border text-primary spinner-border-sm" role="status">
                            <span class="visually-hidden">A processar...</span>
                        </div>
                        <p class="small text-muted mt-2 mb-0">A processar documento, isto pode levar alguns segundos.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secção 2: Resultado do OCR -->
        <div class="col-lg-6 mb-4">
            <div class="card" id="result-card" style="display: none;">
                <div class="card-body">
                    <h4 class="card-title mb-3">2. Dados Extraídos</h4>
                    <div id="extracted-data">
                        <div class="alert alert-info" role="alert">
                            <small>Os dados serão exibidos aqui após o processamento</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas de Erro -->
            <div id="error-alert" class="alert alert-danger" role="alert" style="display: none;">
                <i class="fas fa-exclamation-circle"></i>
                <span id="error-message"></span>
            </div>
        </div>
    </div>

    <!-- Secção 3: Redirecionamento para o formulário -->
    <div class="row mt-4" id="redirect-section" style="display: none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                        <h4 class="card-title mb-0">3. Criar Encomenda</h4>
                    </div>

                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <strong>Dados extraídos com sucesso!</strong>
                        Os artigos e o fornecedor já foram registados no sistema.
                    </div>

                    <div id="ocr-summary" class="mb-4">
                        <!-- preenchido por JS -->
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <a href="{{ route('purchaseOrder.add') }}"
                           id="create-po-btn"
                           class="btn btn-primary">
                            <i class="fas fa-file-invoice"></i> Criar Encomenda
                        </a>
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="fas fa-times-circle"></i> Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- Script -->
<script>
    let currentFile = null;
    let currentStream = null;

    // Iniciar câmara ao carregar
    document.addEventListener('DOMContentLoaded', async function() {
        await startCamera();
        setupEventListeners();
    });

    async function startCamera() {
        try {
            document.getElementById('camera-loading').style.display = 'block';
            const constraints = {
                video: {
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            };
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            document.getElementById('camera-preview').srcObject = currentStream;
            document.getElementById('camera-loading').style.display = 'none';
        } catch (error) {
            console.warn('Câmara não disponível:', error);
            document.getElementById('camera-loading').innerHTML =
                '<p class="small mt-2">Câmara não disponível. Use o carregamento de arquivo.</p>';
            document.getElementById('camera-section').style.display = 'none';
        }
    }

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    }

    function setupEventListeners() {
        document.getElementById('capture-btn').addEventListener('click', capturePhoto);
        document.getElementById('toggle-camera-btn').addEventListener('click', toggleCamera);
        document.getElementById('document-input').addEventListener('change', handleFileSelect);
        document.getElementById('process-btn').addEventListener('click', processOCR);
    }

    function toggleCamera() {
        const btn = document.getElementById('toggle-camera-btn');
        const video = document.getElementById('camera-preview');

        if (currentStream) {
            stopCamera();
            btn.innerHTML = '<i class="fas fa-video"></i> Abrir Câmara';
            video.srcObject = null;
        } else {
            btn.innerHTML = '<i class="fas fa-times"></i> Fechar Câmara';
            startCamera();
        }
    }

    function capturePhoto() {
        const video = document.getElementById('camera-preview');
        const canvas = document.getElementById('capture-canvas');
        const ctx = canvas.getContext('2d');

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0);

        canvas.toBlob(blob => {
            currentFile = new File([blob], 'captured.jpg', { type: 'image/jpeg' });
            document.getElementById('process-btn').disabled = false;
            alert('✓ Foto capturada com sucesso! Clique em "Processar com OCR".');
        }, 'image/jpeg', 0.95);
    }

    function handleFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            currentFile = file;
            document.getElementById('process-btn').disabled = false;
            console.log('Arquivo selecionado:', file.name);
        }
    }

    async function processOCR() {
        if (!currentFile) {
            alert('Por favor, tire uma foto ou selecione um arquivo.');
            return;
        }

        const formData = new FormData();
        formData.append('document', currentFile);

        try {
            document.getElementById('processing-indicator').style.display = 'block';
            document.getElementById('error-alert').style.display = 'none';

            const response = await fetch('{{ route("purchaseOrder.uploadDocument", [], false) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            });

            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers.get('content-type'));

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                document.getElementById('processing-indicator').style.display = 'none';
                const text = await response.text();
                console.error('Resposta não é JSON. Conteúdo:', text.substring(0, 500));
                showError('Erro do servidor: ' + (response.status === 401 ? 'Sessão expirada. Faça login novamente.' : 'Resposta inválida do servidor.'));
                return;
            }

            const result = await response.json();
            document.getElementById('processing-indicator').style.display = 'none';

            if (!result.success) {
                showError(result.error || 'Erro ao processar documento');
                return;
            }

            showResult(result);
            document.getElementById('result-card').style.display = 'block';
            document.getElementById('redirect-section').style.display = 'block';

            // Scroll para a secção de redirecionamento
            document.getElementById('redirect-section').scrollIntoView({ behavior: 'smooth' });
        } catch (error) {
            document.getElementById('processing-indicator').style.display = 'none';
            console.error('Erro completo:', error);
            showError('Erro de conexão: ' + error.message);
        }
    }

    function showError(message) {
        document.getElementById('error-message').textContent = message;
        document.getElementById('error-alert').style.display = 'block';
        document.getElementById('result-card').style.display = 'none';
        document.getElementById('redirect-section').style.display = 'none';
    }

    let ocrResultData = null;

    function showResult(result) {
        ocrResultData = result;
        const enriched = result.enriched || {};
        const supplier = enriched.supplier || {};
        const lines = enriched.lines || [];
        const parsed = result.parsed || {};

        // ── Vista de leitura ──
        let readHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="alert alert-success mb-0 py-2 flex-grow-1 me-2">
                    <strong>✓ Dados Extraídos com Sucesso!</strong>
                </div>
                <button type="button" id="edit-ocr-btn" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-edit"></i> Editar
                </button>
            </div>
            <dl class="row small" id="ocr-read-view">
                <dt class="col-sm-5">Fornecedor:</dt>
                <dd class="col-sm-7">
                    <strong id="read-supplier-name">${supplier.name || parsed.supplier?.nome || '-'}</strong>
                    ${supplier.code ? `<span class="badge bg-info ms-1">Cód. ${supplier.code}</span>` : ''}
                    ${supplier.found ? '' : '<span class="badge bg-warning text-dark ms-1">Criado</span>'}
                </dd>

                <dt class="col-sm-5">NIF:</dt>
                <dd class="col-sm-7"><strong id="read-supplier-nif">${supplier.nif || parsed.supplier?.nif || '-'}</strong></dd>

                <dt class="col-sm-5">Data:</dt>
                <dd class="col-sm-7"><strong id="read-document-date">${parsed.documentDate || '-'}</strong></dd>

                <dt class="col-sm-5">Linhas encontradas:</dt>
                <dd class="col-sm-7"><strong>${lines.length}</strong></dd>
            </dl>
        `;

        // ── Vista de edição (oculta inicialmente) ──
        let editHTML = `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">Editar Dados Extraídos</h6>
                <div>
                    <button type="button" id="save-ocr-btn" class="btn btn-success btn-sm">
                        <i class="fas fa-save"></i> Guardar
                    </button>
                    <button type="button" id="cancel-edit-btn" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                </div>
            </div>
            <div id="ocr-edit-view" style="display: none;">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small mb-0">Fornecedor</label>
                        <input type="text" id="edit-supplier-name" class="form-control form-control-sm"
                               value="${supplier.name || parsed.supplier?.nome || ''}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">NIF</label>
                        <input type="text" id="edit-supplier-nif" class="form-control form-control-sm"
                               value="${supplier.nif || parsed.supplier?.nif || ''}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-0">Data Encomenda</label>
                        <input type="text" id="edit-document-date" class="form-control form-control-sm"
                               value="${parsed.documentDate || ''}">
                    </div>
                </div>
        `;

        if (lines.length > 0) {
            editHTML += `
                <h6 class="mb-2">Linhas</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Artigo</th>
                                <th>Descrição</th>
                                <th class="text-end">Qtd</th>
                                <th class="text-end">Preço Unit.</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            lines.forEach((line, index) => {
                editHTML += `
                    <tr>
                        <td><input type="text" class="form-control form-control-sm edit-line-code"
                                   value="${line.productCode || ''}" data-index="${index}"></td>
                        <td><input type="text" class="form-control form-control-sm edit-line-desc"
                                   value="${line.description || ''}" data-index="${index}"></td>
                        <td><input type="number" step="0.001" class="form-control form-control-sm text-end edit-line-qty"
                                   value="${Number(line.quantity).toFixed(3)}"
                                   data-index="${index}"></td>
                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end edit-line-price"
                                   value="${Number(line.unitPrice).toFixed(2)}" data-index="${index}"></td>
                    </tr>
                `;
            });

            editHTML += `
                        </tbody>
                    </table>
                </div>
            `;
        }

        editHTML += `</div>`;

        document.getElementById('extracted-data').innerHTML = readHTML + editHTML;

        // ── Event Listeners para edição ──
        document.getElementById('edit-ocr-btn').addEventListener('click', function () {
            document.getElementById('ocr-read-view').style.display = 'none';
            document.getElementById('ocr-edit-view').style.display = 'block';
            this.style.display = 'none';
        });

        document.getElementById('cancel-edit-btn').addEventListener('click', function () {
            document.getElementById('ocr-read-view').style.display = '';
            document.getElementById('ocr-edit-view').style.display = 'none';
            document.getElementById('edit-ocr-btn').style.display = '';
        });

        document.getElementById('save-ocr-btn').addEventListener('click', saveEditedData);

        // Preencher sumário da secção de redirecionamento
        const linesCreated = lines.filter(l => !l.found).length;
        const linesExisting = lines.filter(l => l.found).length;
        const supplierStatus = supplier.found ? 'Fornecedor existente' : 'Fornecedor criado';

        document.getElementById('ocr-summary').innerHTML = `
            <div class="card bg-light">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong id="summary-supplier-name">${supplier.name || '-'}</strong><br>
                            <small class="text-muted">${supplierStatus}</small>
                        </div>
                        <div class="col-md-4">
                            <strong>${lines.length} linha(s)</strong><br>
                            <small class="text-muted">
                                ${linesExisting} existente(s), ${linesCreated} criada(s)
                            </small>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <span class="badge bg-success fs-6">
                                <i class="fas fa-check"></i> Pronto
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Atualizar o link do botão para incluir o supplierCode como query param
        const createBtn = document.getElementById('create-po-btn');
        if (supplier.code) {
            createBtn.href = '{{ route("purchaseOrder.add") }}?supplier_code=' + encodeURIComponent(supplier.code);
        }
    }

    async function saveEditedData() {
        const saveBtn = document.getElementById('save-ocr-btn');
        const cancelBtn = document.getElementById('cancel-edit-btn');

        // ── Disable buttons while saving ──
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A guardar...';

        try {
            const supplierName = document.getElementById('edit-supplier-name').value.trim();
            const supplierNif = document.getElementById('edit-supplier-nif').value.trim();

            const lineInputs = document.querySelectorAll('.edit-line-code');
            const lines = [];
            lineInputs.forEach((input) => {
                const index = input.dataset.index;
                lines.push({
                    productCode: input.value.trim(),
                    description: document.querySelector(`.edit-line-desc[data-index="${index}"]`).value.trim(),
                    quantity: parseFloat(document.querySelector(`.edit-line-qty[data-index="${index}"]`).value) || 0,
                    unitPrice: parseFloat(document.querySelector(`.edit-line-price[data-index="${index}"]`).value) || 0,
                });
            });

            const response = await fetch('{{ route("purchaseOrder.updateOCRData", [], false) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({
                    supplier: { name: supplierName, nif: supplierNif },
                    lines: lines,
                }),
            });

            if (!response.ok) {
                const text = await response.text();
                throw new Error(`Servidor respondeu com ${response.status}: ${text.slice(0, 200)}`);
            }

            const result = await response.json();
            if (result.success) {
                // Atualiza a vista de leitura
                document.getElementById('read-supplier-name').textContent = supplierName || '-';
                document.getElementById('read-supplier-nif').textContent = supplierNif || '-';
                document.getElementById('summary-supplier-name').textContent = supplierName || '-';

                document.getElementById('ocr-read-view').style.display = '';
                document.getElementById('ocr-edit-view').style.display = 'none';
                document.getElementById('edit-ocr-btn').style.display = '';

                alert('✓ Dados atualizados com sucesso!');
            }
        } catch (error) {
            console.error('Erro ao guardar dados:', error);
            alert('Erro ao guardar alterações: ' + error.message);
        } finally {
            // ── Re-enable buttons ──
            saveBtn.disabled = false;
            cancelBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Guardar';
        }
    }
</script>

<style>
    #purchaseOrderOcrPage .card-title {
        font-size: 1rem;
        font-weight: 600;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: 600;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .camera-wrapper {
        background: #000;
        border-radius: 8px;
        overflow: hidden;
    }

    #camera-preview {
        border-radius: 8px;
        object-fit: cover;
    }

    .card {
        border-radius: 8px;
        padding: 1rem 1.5rem;
    }

    .form-control, .form-control:focus {
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }

    #result-card dl dt,
    #result-card dl dd {
        margin-bottom: 0.35rem;
    }

    .btn-sm {
        font-size: 0.875rem;
    }

    .spinner-border-sm {
        width: 1.5rem;
        height: 1.5rem;
    }
</style>
@endsection
