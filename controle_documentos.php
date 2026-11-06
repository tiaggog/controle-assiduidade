<?php
require_once 'conexao.php';

$mensagem = '';
$hoje = date('Y-m-d');
$limite_aviso = date('Y-m-d', strtotime('+' . DOCUMENTO_AVISO_DIAS . ' days'));

// Anexos: foto ou PDF, guardados fora do acesso direto (uploads/.htaccess bloqueia) e abertos só por esta página
const PASTA_DOCUMENTOS = __DIR__ . '/uploads/documentos/';
const TAMANHO_MAXIMO_ARQUIVO = 10 * 1024 * 1024;
const TIPOS_ARQUIVO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

function remover_arquivo_documento($arquivo) {
    if ($arquivo && is_file(PASTA_DOCUMENTOS . basename($arquivo))) {
        unlink(PASTA_DOCUMENTOS . basename($arquivo));
    }
}

// Abre o anexo de um documento (exibe no navegador; foto ou PDF)
if (isset($_GET['arquivo'])) {
    $stmt = $pdo->prepare("SELECT d.arquivo, d.arquivo_nome, d.arquivo_mime FROM funcionario_documentos d WHERE d.funcionario_id = :funcionario_id AND d.tipo_id = :tipo_id");
    $stmt->execute([':funcionario_id' => (int)($_GET['funcionario_id'] ?? 0), ':tipo_id' => (int)($_GET['tipo_id'] ?? 0)]);
    $anexo = pode_ver_funcionario($pdo, (int)($_GET['funcionario_id'] ?? 0)) ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $caminho = $anexo && $anexo['arquivo'] ? PASTA_DOCUMENTOS . basename($anexo['arquivo']) : null;
    if (!$caminho || !is_file($caminho) || !isset(TIPOS_ARQUIVO[$anexo['arquivo_mime']])) {
        http_response_code(404);
        die('Arquivo não encontrado.');
    }
    header('Content-Type: ' . $anexo['arquivo_mime']);
    header('Content-Length: ' . filesize($caminho));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $anexo['arquivo_nome'] ?: basename($caminho)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($caminho);
    exit;
}

// Configuração dos documentos (somente o admin): renomear, exigir, adicionar novos e excluir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_tipos'])) {
    if (!usuario_admin()) {
        $mensagem = alerta('danger', 'Somente o administrador pode configurar os documentos.');
    } else {
        $arquivos_excluidos = [];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE documentos_tipos SET nome = :nome, exigido = :exigido WHERE id = :id");
            $stmt_arquivos = $pdo->prepare("SELECT arquivo FROM funcionario_documentos WHERE tipo_id = :id AND arquivo IS NOT NULL");
            $stmt_excluir = $pdo->prepare("DELETE FROM documentos_tipos WHERE id = :id");
            foreach ($_POST['tipos'] ?? [] as $id => $dados) {
                // Excluir o documento apaga também o que foi cadastrado dele nos colaboradores
                if (!empty($dados['excluir'])) {
                    $stmt_arquivos->execute([':id' => (int)$id]);
                    $arquivos_excluidos = array_merge($arquivos_excluidos, $stmt_arquivos->fetchAll(PDO::FETCH_COLUMN));
                    $stmt_excluir->execute([':id' => (int)$id]);
                    continue;
                }
                $nome = trim($dados['nome'] ?? '');
                if ($nome === '') {
                    $nome = 'Documento ' . (int)$id;
                }
                $stmt->execute([':nome' => mb_substr($nome, 0, 60), ':exigido' => !empty($dados['exigido']) ? 1 : 0, ':id' => (int)$id]);
            }

            $stmt_novo = $pdo->prepare("INSERT INTO documentos_tipos (id, nome, exigido) VALUES (:id, :nome, :exigido)");
            foreach ($_POST['novos'] ?? [] as $dados) {
                $nome = trim($dados['nome'] ?? '');
                if ($nome === '') {
                    continue;
                }
                $proximo = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM documentos_tipos")->fetchColumn();
                if ($proximo > 255) {
                    throw new Exception('Limite de documentos atingido.');
                }
                $stmt_novo->execute([':id' => $proximo, ':nome' => mb_substr($nome, 0, 60), ':exigido' => !empty($dados['exigido']) ? 1 : 0]);
            }
            if ((int)$pdo->query("SELECT COUNT(*) FROM documentos_tipos")->fetchColumn() === 0) {
                throw new Exception('mantenha pelo menos um documento.');
            }
            $pdo->commit();
            array_map('remover_arquivo_documento', $arquivos_excluidos);
            $mensagem = alerta('success', 'Configuração dos documentos salva com sucesso!');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensagem = alerta('danger', 'Não foi possível salvar a configuração: ' . e($e->getMessage()));
        }
    }
}

// Documentos de um colaborador: data de validade e arquivo (foto ou PDF) de cada um
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_documentos'])) {
    $funcionario_id = (int)($_POST['funcionario_id'] ?? 0);
    $stmt_nome = $pdo->prepare("SELECT nome FROM funcionarios WHERE id = :id");
    $stmt_nome->execute([':id' => $funcionario_id]);
    $nome_func = pode_ver_funcionario($pdo, $funcionario_id) ? $stmt_nome->fetchColumn() : false;

    if (!$nome_func) {
        $mensagem = alerta('danger', 'Colaborador não encontrado.');
    } else {
        $novos_arquivos = [];   // gravados nesta operação (apagados se algo der errado)
        $arquivos_antigos = []; // substituídos/removidos (apagados só depois de salvar)
        try {
            $stmt_atual = $pdo->prepare("SELECT tipo_id, arquivo FROM funcionario_documentos WHERE funcionario_id = :funcionario_id");
            $stmt_atual->execute([':funcionario_id' => $funcionario_id]);
            $arquivo_atual = $stmt_atual->fetchAll(PDO::FETCH_KEY_PAIR);

            $pdo->beginTransaction();
            $stmt_salvar = $pdo->prepare("INSERT INTO funcionario_documentos (funcionario_id, tipo_id, data_vencimento)
                                          VALUES (:funcionario_id, :tipo_id, :vencimento)
                                          ON DUPLICATE KEY UPDATE data_vencimento = VALUES(data_vencimento)");
            $stmt_anexo = $pdo->prepare("UPDATE funcionario_documentos SET arquivo = :arquivo, arquivo_nome = :nome, arquivo_mime = :mime
                                         WHERE funcionario_id = :funcionario_id AND tipo_id = :tipo_id");
            $stmt_remover = $pdo->prepare("DELETE FROM funcionario_documentos WHERE funcionario_id = :funcionario_id AND tipo_id = :tipo_id");
            foreach (tipos_documento($pdo) as $tipo) {
                $dados = $_POST['docs'][$tipo['id']] ?? [];
                $chave = ['funcionario_id' => $funcionario_id, 'tipo_id' => $tipo['id']];
                $envio = $_FILES['arquivo']['error'][$tipo['id']] ?? UPLOAD_ERR_NO_FILE;
                $antigo = $arquivo_atual[$tipo['id']] ?? null;

                if ($envio !== UPLOAD_ERR_NO_FILE && $envio !== UPLOAD_ERR_OK) {
                    throw new Exception('Falha ao enviar o arquivo de ' . $tipo['nome'] . ($envio === UPLOAD_ERR_INI_SIZE || $envio === UPLOAD_ERR_FORM_SIZE ? ' (arquivo muito grande).' : '.'));
                }

                $vencimento = ($dados['vencimento'] ?? '') !== '' ? $dados['vencimento'] : null;
                if ($vencimento && !DateTime::createFromFormat('Y-m-d', $vencimento)) {
                    throw new Exception('Data de validade inválida em ' . $tipo['nome'] . '.');
                }

                // Todo documento cadastrado tem validade obrigatória; sem validade e sem arquivo ele fica pendente
                $mantem_arquivo = $antigo && empty($dados['remover_arquivo']);
                if (!$vencimento) {
                    if ($envio === UPLOAD_ERR_OK || $mantem_arquivo) {
                        throw new Exception('Informe a data de validade de ' . $tipo['nome'] . '.');
                    }
                    $stmt_remover->execute($chave);
                    if ($antigo) {
                        $arquivos_antigos[] = $antigo;
                    }
                    continue;
                }
                $stmt_salvar->execute($chave + ['vencimento' => $vencimento]);

                if ($envio === UPLOAD_ERR_OK) {
                    $temporario = $_FILES['arquivo']['tmp_name'][$tipo['id']];
                    if (filesize($temporario) > TAMANHO_MAXIMO_ARQUIVO) {
                        throw new Exception('O arquivo de ' . $tipo['nome'] . ' passa de 10 MB.');
                    }
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporario);
                    if (!isset(TIPOS_ARQUIVO[$mime])) {
                        throw new Exception('O arquivo de ' . $tipo['nome'] . ' precisa ser foto (JPG, PNG ou WEBP) ou PDF.');
                    }
                    $nome_arquivo = bin2hex(random_bytes(16)) . '.' . TIPOS_ARQUIVO[$mime];
                    if (!is_dir(PASTA_DOCUMENTOS)) {
                        mkdir(PASTA_DOCUMENTOS, 0775, true);
                    }
                    if (!move_uploaded_file($temporario, PASTA_DOCUMENTOS . $nome_arquivo)) {
                        throw new Exception('Não foi possível guardar o arquivo de ' . $tipo['nome'] . '.');
                    }
                    $novos_arquivos[] = $nome_arquivo;
                    $nome_original = mb_substr(basename($_FILES['arquivo']['name'][$tipo['id']]), 0, 255);
                    $stmt_anexo->execute($chave + ['arquivo' => $nome_arquivo, 'nome' => $nome_original, 'mime' => $mime]);
                    if ($antigo) {
                        $arquivos_antigos[] = $antigo;
                    }
                } elseif (!empty($dados['remover_arquivo']) && $antigo) {
                    $stmt_anexo->execute($chave + ['arquivo' => null, 'nome' => null, 'mime' => null]);
                    $arquivos_antigos[] = $antigo;
                }
            }
            $pdo->commit();
            array_map('remover_arquivo_documento', $arquivos_antigos);
            $mensagem = alerta('success', 'Documentos de ' . e($nome_func) . ' atualizados com sucesso!');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            array_map('remover_arquivo_documento', $novos_arquivos);
            $mensagem = alerta('danger', 'Não foi possível salvar: ' . e($e->getMessage()));
        }
    }
}

$filtro_empresa = (int)($_GET['empresa_id'] ?? 0);
$filtro_filial = filial_filtrada();
$filiais = lista_filiais($pdo);
$filtro_tipo = in_array($_GET['tipo'] ?? '', ['Funcionario', 'Diarista'], true) ? $_GET['tipo'] : '';
$filtro_situacao = in_array($_GET['situacao'] ?? '', ['irregulares', 'vencendo', 'regulares'], true) ? $_GET['situacao'] : '';

$empresas = lista_empresas($pdo);
$tipos = tipos_documento($pdo);
// Quantos colaboradores já têm cada documento cadastrado (aviso ao excluir)
$cadastros_por_tipo = $pdo->query("SELECT tipo_id, COUNT(*) FROM funcionario_documentos GROUP BY tipo_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$exigidos = array_filter($tipos, fn($t) => (int)$t['exigido'] === 1);

// Colaboradores ativos (diaristas sem vínculo aparecem em qualquer empresa)
$where = ['f.status = 1'];
if ($filtro_filial) {
    $where[] = 'f.filial_id = ' . (int)$filtro_filial;
}
$params = [];
if ($filtro_empresa) {
    $where[] = '(f.empresa_id = :empresa_id OR (f.tipo = \'Diarista\' AND f.empresa_id IS NULL))';
    $params[':empresa_id'] = $filtro_empresa;
}
if ($filtro_tipo) {
    $where[] = 'f.tipo = :tipo';
    $params[':tipo'] = $filtro_tipo;
}
$stmt = $pdo->prepare("SELECT f.id, f.nome, f.tipo, e.razao_social, fi.nome AS filial FROM funcionarios f
                       LEFT JOIN filiais fi ON fi.id = f.filial_id
                       LEFT JOIN empresas_terceirizadas e ON e.id = f.empresa_id
                       WHERE " . implode(' AND ', $where) . "
                       ORDER BY f.tipo ASC, f.nome ASC");
$stmt->execute($params);
$colaboradores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Documentos cadastrados: [funcionario_id => [tipo_id => validade|null]] e anexos: [funcionario_id => [tipo_id => nome do arquivo]]
$entregues = [];
$anexos = [];
foreach ($pdo->query("SELECT funcionario_id, tipo_id, data_vencimento, arquivo, arquivo_nome FROM funcionario_documentos")->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $entregues[$d['funcionario_id']][$d['tipo_id']] = $d['data_vencimento'];
    if ($d['arquivo']) {
        $anexos[$d['funcionario_id']][$d['tipo_id']] = $d['arquivo_nome'] ?: 'arquivo';
    }
}

function link_anexo($funcionario_id, $tipo_id) {
    return 'controle_documentos.php?arquivo=1&funcionario_id=' . (int)$funcionario_id . '&tipo_id=' . (int)$tipo_id;
}

// Situação de cada colaborador hoje: irregular (bloqueado na frequência), vencendo ou regular
$contagem = ['irregulares' => 0, 'vencendo' => 0, 'regulares' => 0];
foreach ($colaboradores as &$colab) {
    $colab['problemas'] = problemas_documentos($pdo, $colab['id'], $hoje);
    $vencendo = false;
    foreach ($exigidos as $t) {
        $venc = $entregues[$colab['id']][$t['id']] ?? null;
        if ($venc && $venc >= $hoje && $venc <= $limite_aviso) {
            $vencendo = true;
        }
    }
    $colab['situacao'] = $colab['problemas'] ? 'irregulares' : ($vencendo ? 'vencendo' : 'regulares');
    $contagem[$colab['situacao']]++;
}
unset($colab);

if ($filtro_situacao) {
    $colaboradores = array_values(array_filter($colaboradores, fn($c) => $c['situacao'] === $filtro_situacao));
}

// Situação da validade: vencido, faltando até 7 dias (alerta) ou em dia. Retorna [classe do selo, texto]
function situacao_validade($validade, $hoje) {
    $dias = (int)round((strtotime($validade) - strtotime($hoje)) / 86400);
    if ($dias < 0) {
        return ['badge-danger', 'Vencido'];
    }
    if ($dias === 0) {
        return ['badge-warning', 'Vence hoje'];
    }
    if ($dias <= DOCUMENTO_AVISO_DIAS) {
        return ['badge-warning', $dias === 1 ? 'Vence em 1 dia' : "Vence em $dias dias"];
    }
    return ['badge-success', 'Em dia'];
}

// Selo de um documento na tabela, com a data de validade embaixo
function selo_documento(array $tipo, $cadastrado, $validade, $hoje) {
    if (!$cadastrado || !$validade) {
        return (int)$tipo['exigido'] === 1
            ? '<span class="badge-app badge-danger">Pendente</span>'
            : '<span class="text-muted">—</span>';
    }
    [$classe, $texto] = situacao_validade($validade, $hoje);
    $icone = ['badge-danger' => 'x-octagon-fill', 'badge-warning' => 'exclamation-triangle-fill', 'badge-success' => 'check-circle-fill'][$classe];
    return '<span class="badge-app ' . $classe . '"><i class="bi bi-' . $icone . ' me-1"></i>' . $texto . '</span>'
         . '<div class="data-validade">' . date('d/m/Y', strtotime($validade)) . '</div>';
}

render_cabecalho('Controle de Documentos', 'Documentos de cada colaborador e seus vencimentos. Quem estiver com documento exigido pendente ou vencido fica bloqueado no lançamento de frequência.');
?>
<style>
    .tabela-docs th.col-doc { min-width: 92px; text-align: center; font-size: 0.7rem; line-height: 1.2; white-space: normal; }
    .tabela-docs td.col-doc { text-align: center; white-space: nowrap; }
    .tabela-docs .marca-exigido { display: block; color: var(--app-danger); font-size: 0.62rem; font-weight: 700; letter-spacing: 0.04em; margin-top: 2px; }
    .tabela-docs td:first-child { min-width: 200px; }
    .linha-irregular td:first-child { box-shadow: inset 3px 0 0 #d92d20; }
    .doc-modal-linha { display: grid; grid-template-columns: 1fr auto 160px; gap: 0.75rem; align-items: center; padding: 0.6rem 0; border-bottom: 1px solid var(--app-border); }
    .doc-modal-linha:last-child { border-bottom: 0; }
    .doc-modal-linha .doc-anexo { grid-column: 1 / -1; }
    .foi-validado input[type=date]:invalid { border-color: #d92d20 !important; background-color: #fef3f2 !important; box-shadow: 0 0 0 2px rgba(217, 45, 32, 0.15) !important; }
    .col-doc .badge-app::before, .js-situacao .badge-app::before { display: none; }
    .data-validade { font-size: 0.68rem; color: var(--app-muted, #667085); margin-top: 3px; font-variant-numeric: tabular-nums; }
    .link-anexo { margin-left: 2px; color: var(--app-primary); font-size: 0.95rem; }
    @media (max-width: 575.98px) {
        .doc-modal-linha { grid-template-columns: 1fr auto; }
        .doc-modal-linha input[type=date] { grid-column: 1 / -1; }
    }
</style>

<div class="container pb-5">
    <?php echo $mensagem; ?>

    <?php if (!$exigidos): ?>
    <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-warning-soft); color: var(--app-warning); border: 1px solid #fedf89;">
        <i class="bi bi-info-circle-fill"></i>
        <div><strong>Nenhum documento está marcado como exigido.</strong> Por enquanto ninguém é bloqueado na frequência. <?php echo usuario_admin() ? 'Defina os nomes e marque os documentos exigidos em <strong>Configurar documentos</strong>.' : 'O administrador precisa configurar os documentos exigidos.'; ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="?<?php echo e(http_build_query(array_merge($_GET, ['situacao' => '']))); ?>"><div class="metric-card"><div class="metric-label"><i class="bi bi-people"></i>Colaboradores ativos</div><div class="metric-value"><?php echo array_sum($contagem); ?></div></div></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="?<?php echo e(http_build_query(array_merge($_GET, ['situacao' => 'regulares']))); ?>"><div class="metric-card"><div class="metric-label"><i class="bi bi-check-circle"></i>Regulares</div><div class="metric-value" style="color: var(--app-success)"><?php echo $contagem['regulares']; ?></div></div></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="?<?php echo e(http_build_query(array_merge($_GET, ['situacao' => 'vencendo']))); ?>"><div class="metric-card"><div class="metric-label"><i class="bi bi-hourglass-split"></i>Vencem em até <?php echo DOCUMENTO_AVISO_DIAS; ?> dias</div><div class="metric-value" style="color: var(--app-warning)"><?php echo $contagem['vencendo']; ?></div></div></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="?<?php echo e(http_build_query(array_merge($_GET, ['situacao' => 'irregulares']))); ?>"><div class="metric-card"><div class="metric-label"><i class="bi bi-slash-circle"></i>Bloqueados</div><div class="metric-value" style="color: var(--app-danger)"><?php echo $contagem['irregulares']; ?></div></div></a></div>
    </div>

    <?php if (usuario_admin()): // Configuração dos documentos: somente o admin vê e altera ?>
    <div class="card card-custom overflow-hidden mb-4">
        <div class="p-3 px-4 d-flex justify-content-between align-items-center" role="button" data-bs-toggle="collapse" data-bs-target="#configDocumentos" aria-expanded="<?php echo $exigidos ? 'false' : 'true'; ?>">
            <h5 class="m-0"><i class="bi bi-gear me-2 text-muted"></i>Configurar documentos <span class="text-muted fw-normal small">(<?php echo count($exigidos); ?> de <?php echo count($tipos); ?> exigidos)</span></h5>
            <i class="bi bi-chevron-down text-muted"></i>
        </div>
        <div class="collapse <?php echo $exigidos ? '' : 'show'; ?>" id="configDocumentos">
            <form method="POST" action="controle_documentos.php?<?php echo e(http_build_query($_GET)); ?>" class="p-3 px-4 border-top">
                <input type="hidden" name="salvar_tipos" value="1">
                <p class="small text-muted mb-3">Só os documentos marcados como <strong>exigido</strong> bloqueiam o lançamento de frequência. Os demais podem ser registrados, mas não bloqueiam. Use <strong>Adicionar documento</strong> para incluir quantos precisar: eles aparecem na tabela e seguem as mesmas regras.</p>
                <div class="row g-3" id="lista_tipos">
                    <?php foreach ($tipos as $posicao => $tipo): $tid = (int)$tipo['id']; $qtd = $cadastros_por_tipo[$tid] ?? 0; ?>
                    <div class="col-sm-6 col-lg-3 js-tipo">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small mb-0" for="nome_tipo_<?php echo $tid; ?>">Documento <?php echo $posicao + 1; ?></label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-danger" title="Excluir documento"
                                    onclick="excluirTipo(this, <?php echo e(json_encode($tipo['nome'])); ?>, <?php echo $qtd; ?>)"><i class="bi bi-trash3"></i></button>
                        </div>
                        <input type="hidden" name="tipos[<?php echo $tid; ?>][excluir]" value="" class="js-excluir">
                        <input type="text" id="nome_tipo_<?php echo $tid; ?>" name="tipos[<?php echo $tid; ?>][nome]" class="form-control form-control-sm mb-1" maxlength="60" value="<?php echo e($tipo['nome']); ?>">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="exigido_<?php echo $tid; ?>" name="tipos[<?php echo $tid; ?>][exigido]" value="1" <?php echo (int)$tipo['exigido'] === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="exigido_<?php echo $tid; ?>">Exigido</label>
                        </div>
                        <div class="small text-danger fw-semibold js-aviso-excluir" style="display:none"><i class="bi bi-trash3 me-1"></i>Será excluído ao salvar · <a href="#" onclick="desfazerExclusao(this); return false;">desfazer</a></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
                    <button type="button" class="btn btn-light-app" onclick="adicionarTipo()"><i class="bi bi-plus-lg"></i>Adicionar documento</button>
                    <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar configuração</button>
                </div>
            </form>
            <template id="modelo_tipo">
                <div class="col-sm-6 col-lg-3 js-tipo js-tipo-novo">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small mb-0 text-success">Novo documento</label>
                        <button type="button" class="btn btn-link btn-sm p-0 text-danger" title="Remover" onclick="this.closest('.js-tipo').remove()"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <input type="text" name="novos[__i__][nome]" class="form-control form-control-sm mb-1" maxlength="60" placeholder="Nome do documento" required>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="exigido_novo___i__" name="novos[__i__][exigido]" value="1">
                        <label class="form-check-label small" for="exigido_novo___i__">Exigido</label>
                    </div>
                </div>
            </template>
        </div>
    </div>
    <?php endif; ?>

    <div class="card card-custom overflow-hidden">
        <div class="p-3 px-4 border-bottom d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <h5 class="m-0">Documentos por colaborador <span class="text-muted fw-normal small">(<?php echo count($colaboradores); ?>)</span></h5>
            <form method="GET" class="d-flex flex-wrap gap-2">
                <select name="empresa_id" class="form-select form-select-sm" style="min-width: 180px; max-width: 260px" onchange="this.form.submit()">
                    <option value="">Todas as empresas</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo $filtro_empresa === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['razao_social']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="min-width: 150px"><?php render_campo_filial($filiais, $filtro_filial, 'filial_id', 'Todas as filiais', 'form-select form-select-sm', ' onchange="this.form.submit()"'); ?></div>
                <select name="tipo" class="form-select form-select-sm" style="min-width: 140px" onchange="this.form.submit()">
                    <option value="">Todos os tipos</option>
                    <option value="Funcionario" <?php echo $filtro_tipo === 'Funcionario' ? 'selected' : ''; ?>>Funcionários</option>
                    <option value="Diarista" <?php echo $filtro_tipo === 'Diarista' ? 'selected' : ''; ?>>Diaristas</option>
                </select>
                <select name="situacao" class="form-select form-select-sm" style="min-width: 150px" onchange="this.form.submit()">
                    <option value="">Todas as situações</option>
                    <option value="irregulares" <?php echo $filtro_situacao === 'irregulares' ? 'selected' : ''; ?>>Bloqueados</option>
                    <option value="vencendo" <?php echo $filtro_situacao === 'vencendo' ? 'selected' : ''; ?>>Vencendo</option>
                    <option value="regulares" <?php echo $filtro_situacao === 'regulares' ? 'selected' : ''; ?>>Regulares</option>
                </select>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tabela-docs">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <?php foreach ($tipos as $tipo): ?>
                            <th class="col-doc"><?php echo e($tipo['nome']); ?><?php echo (int)$tipo['exigido'] === 1 ? '<span class="marca-exigido">EXIGIDO</span>' : ''; ?></th>
                        <?php endforeach; ?>
                        <th class="text-center">Situação</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($colaboradores): ?>
                        <?php foreach ($colaboradores as $colab):
                            $docs = $entregues[$colab['id']] ?? [];
                            $dados_modal = [];
                            foreach ($tipos as $tipo) {
                                $anexo = $anexos[$colab['id']][$tipo['id']] ?? null;
                                $dados_modal[$tipo['id']] = [
                                    'vencimento' => $docs[$tipo['id']] ?? '',
                                    'arquivo'    => $anexo,
                                    'link'       => $anexo ? link_anexo($colab['id'], $tipo['id']) : '',
                                ];
                            }
                        ?>
                        <tr class="<?php echo $colab['situacao'] === 'irregulares' ? 'linha-irregular' : ''; ?>">
                            <td>
                                <div class="fw-medium"><?php echo e($colab['nome']); ?></div>
                                <div class="small text-muted">
                                    <?php echo $colab['tipo'] === 'Diarista' ? 'Diarista' : 'Funcionário'; ?> ·
                                    <?php echo $colab['razao_social'] ? e($colab['razao_social']) : 'Todas as empresas'; ?> ·
                                    <?php echo $colab['filial'] ? e($colab['filial']) : '<span class="text-warning">Sem filial</span>'; ?>
                                </div>
                            </td>
                            <?php foreach ($tipos as $tipo): ?>
                                <td class="col-doc">
                                    <?php echo selo_documento($tipo, array_key_exists($tipo['id'], $docs), $docs[$tipo['id']] ?? null, $hoje); ?>
                                    <?php if (isset($anexos[$colab['id']][$tipo['id']])): ?>
                                        <a href="<?php echo e(link_anexo($colab['id'], $tipo['id'])); ?>" target="_blank" rel="noopener" class="link-anexo" title="Abrir arquivo: <?php echo e($anexos[$colab['id']][$tipo['id']]); ?>"><i class="bi bi-paperclip"></i></a>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php if ($colab['situacao'] === 'irregulares'): ?>
                                    <span class="badge-app badge-danger" title="<?php echo e(implode("\n", $colab['problemas'])); ?>"><i class="bi bi-slash-circle me-1"></i>Bloqueado</span>
                                <?php elseif ($colab['situacao'] === 'vencendo'): ?>
                                    <span class="badge-app badge-warning">Vencendo</span>
                                <?php else: ?>
                                    <span class="badge-app badge-success">Regular</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light-app" title="Editar documentos"
                                        data-bs-toggle="modal" data-bs-target="#modalDocumentos"
                                        data-id="<?php echo (int)$colab['id']; ?>"
                                        data-nome="<?php echo e($colab['nome']); ?>"
                                        data-docs="<?php echo e(json_encode($dados_modal)); ?>"
                                        onclick="abrirDocumentos(this)"><i class="bi bi-pencil me-0"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?php echo count($tipos) + 3; ?>" class="table-empty"><i class="bi bi-folder2-open"></i>Nenhum colaborador encontrado com esse filtro.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-3 px-4 border-top small text-muted">
            <span class="badge-app badge-success"><i class="bi bi-check-circle-fill me-1"></i>Em dia</span> validade com mais de <?php echo DOCUMENTO_AVISO_DIAS; ?> dias ·
            <span class="badge-app badge-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>Vence em X dias</span> nos últimos <?php echo DOCUMENTO_AVISO_DIAS; ?> dias antes do vencimento ·
            <span class="badge-app badge-danger"><i class="bi bi-x-octagon-fill me-1"></i>Vencido</span> validade passou ·
            <span class="badge-app badge-danger">Pendente</span> não cadastrado ·
            <span class="text-muted">—</span> não exigido
        </div>
    </div>
</div>

<!-- Edição dos documentos de um colaborador -->
<div class="modal fade" id="modalDocumentos" tabindex="-1" aria-labelledby="modalDocumentosTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="controle_documentos.php?<?php echo e(http_build_query($_GET)); ?>" class="modal-content" enctype="multipart/form-data">
            <input type="hidden" name="salvar_documentos" value="1">
            <input type="hidden" name="funcionario_id" id="doc_funcionario_id">
            <div class="modal-header">
                <h5 class="modal-title" id="modalDocumentosTitulo"><i class="bi bi-folder-check me-2 text-muted"></i>Documentos de <span id="doc_nome"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Selecione a data de validade de cada documento e anexe a foto ou o PDF (até 10 MB). A validade é obrigatória: documento sem data fica como pendente.</p>
                <?php foreach ($tipos as $tipo): $tid = (int)$tipo['id']; ?>
                <div class="doc-modal-linha js-doc" data-tipo="<?php echo $tid; ?>" data-exigido="<?php echo (int)$tipo['exigido']; ?>">
                    <div>
                        <span class="fw-medium"><?php echo e($tipo['nome']); ?></span>
                        <?php if ((int)$tipo['exigido'] === 1): ?><span class="badge-app badge-danger ms-1">Exigido</span><?php endif; ?>
                    </div>
                    <span class="js-situacao" id="situacao_<?php echo $tid; ?>"></span>
                    <div>
                        <label class="form-label small mb-1" for="vencimento_<?php echo $tid; ?>">Validade</label>
                        <input type="date" class="form-control form-control-sm" id="vencimento_<?php echo $tid; ?>" name="docs[<?php echo $tid; ?>][vencimento]" onchange="mostrarSituacao(<?php echo $tid; ?>)" oninput="mostrarSituacao(<?php echo $tid; ?>)">
                    </div>
                    <div class="doc-anexo">
                        <input type="file" class="form-control form-control-sm" id="arquivo_<?php echo $tid; ?>" name="arquivo[<?php echo $tid; ?>]" accept="image/*,application/pdf" onchange="arquivoEscolhido(this)">
                        <div class="small mt-1" id="anexo_atual_<?php echo $tid; ?>" style="display:none">
                            <i class="bi bi-paperclip"></i> <a href="#" target="_blank" rel="noopener" id="anexo_link_<?php echo $tid; ?>"></a>
                            <span class="form-check form-check-inline ms-2 mb-0">
                                <input class="form-check-input" type="checkbox" id="remover_<?php echo $tid; ?>" name="docs[<?php echo $tid; ?>][remover_arquivo]" value="1" onchange="exigirValidade(<?php echo $tid; ?>)">
                                <label class="form-check-label text-danger" for="remover_<?php echo $tid; ?>">remover arquivo</label>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar documentos</button>
            </div>
        </form>
    </div>
</div>

<script>
const HOJE = '<?php echo $hoje; ?>';
const DIAS_AVISO = <?php echo DOCUMENTO_AVISO_DIAS; ?>;

function abrirDocumentos(botao) {
    document.getElementById('doc_funcionario_id').value = botao.dataset.id;
    document.getElementById('doc_nome').textContent = botao.dataset.nome;
    const docs = JSON.parse(botao.dataset.docs);
    document.querySelectorAll('#modalDocumentos .js-doc').forEach(function (linha) {
        const tipo = linha.dataset.tipo;
        const doc = docs[tipo] || {};
        document.getElementById('vencimento_' + tipo).value = doc.vencimento || '';
        document.getElementById('arquivo_' + tipo).value = '';
        document.getElementById('remover_' + tipo).checked = false;
        const link = document.getElementById('anexo_link_' + tipo);
        document.getElementById('anexo_atual_' + tipo).style.display = doc.arquivo ? '' : 'none';
        link.textContent = doc.arquivo || '';
        link.href = doc.link || '#';
        mostrarSituacao(tipo);
        exigirValidade(tipo);
    });
    document.getElementById('modalDocumentos').classList.remove('foi-validado');
}

// Documento com arquivo (novo ou mantido) precisa de validade
function exigirValidade(tipo) {
    const temArquivo = document.getElementById('arquivo_' + tipo).files.length > 0
        || (document.getElementById('anexo_link_' + tipo).textContent !== '' && !document.getElementById('remover_' + tipo).checked);
    document.getElementById('vencimento_' + tipo).required = temArquivo;
}

// Ao salvar, destaca em vermelho a validade que falta
document.getElementById('modalDocumentos').querySelector('button[type=submit]').addEventListener('click', function () {
    document.getElementById('modalDocumentos').classList.add('foi-validado');
});

// Mesmo selo da tabela: Vencido, Vence hoje / Vence em X dias (até 7) ou Em dia
function mostrarSituacao(tipo) {
    const validade = document.getElementById('vencimento_' + tipo).value;
    const alvo = document.getElementById('situacao_' + tipo);
    if (!validade) {
        // Sem data o documento não está cadastrado: pendente se for exigido
        const exigido = alvo.closest('.js-doc').dataset.exigido === '1';
        alvo.innerHTML = exigido ? '<span class="badge-app badge-danger"><i class="bi bi-x-octagon-fill me-1"></i>Pendente</span>' : '';
        return;
    }
    const dias = Math.round((new Date(validade + 'T00:00:00') - new Date(HOJE + 'T00:00:00')) / 86400000);
    let classe = 'badge-success', icone = 'check-circle-fill', texto = 'Em dia';
    if (dias < 0) {
        classe = 'badge-danger'; icone = 'x-octagon-fill'; texto = 'Vencido';
    } else if (dias <= DIAS_AVISO) {
        classe = 'badge-warning'; icone = 'exclamation-triangle-fill';
        texto = dias === 0 ? 'Vence hoje' : (dias === 1 ? 'Vence em 1 dia' : 'Vence em ' + dias + ' dias');
    }
    alvo.innerHTML = '<span class="badge-app ' + classe + '"><i class="bi bi-' + icone + ' me-1"></i>' + texto + '</span>';
}

// Configuração: novos documentos e exclusão (só vale ao salvar)
let indiceTipo = 0;
function adicionarTipo() {
    const html = document.getElementById('modelo_tipo').innerHTML.replace(/__i__/g, indiceTipo++);
    document.getElementById('lista_tipos').insertAdjacentHTML('beforeend', html);
    const novos = document.querySelectorAll('#lista_tipos .js-tipo-novo input[type=text]');
    novos[novos.length - 1].focus();
}

function excluirTipo(botao, nome, cadastros) {
    const aviso = cadastros > 0
        ? 'Excluir "' + nome + '"? ' + cadastros + (cadastros === 1 ? ' colaborador tem' : ' colaboradores têm') + ' este documento cadastrado: a validade e o arquivo serão apagados.'
        : 'Excluir "' + nome + '"?';
    if (!confirm(aviso)) return;
    const card = botao.closest('.js-tipo');
    card.querySelector('.js-excluir').value = '1';
    card.querySelectorAll('input[type=text], input[type=checkbox]').forEach(c => c.disabled = true);
    card.querySelector('.js-aviso-excluir').style.display = '';
    card.style.opacity = '0.6';
}

function desfazerExclusao(link) {
    const card = link.closest('.js-tipo');
    card.querySelector('.js-excluir').value = '';
    card.querySelectorAll('input[type=text], input[type=checkbox]').forEach(c => c.disabled = false);
    card.querySelector('.js-aviso-excluir').style.display = 'none';
    card.style.opacity = '';
}

function arquivoEscolhido(campo) {
    if (campo.files.length && campo.files[0].size > <?php echo TAMANHO_MAXIMO_ARQUIVO; ?>) {
        alert('O arquivo passa de 10 MB. Escolha um arquivo menor.');
        campo.value = '';
    }
    exigirValidade(campo.id.replace('arquivo_', ''));
}
</script>

<?php render_rodape(); ?>
