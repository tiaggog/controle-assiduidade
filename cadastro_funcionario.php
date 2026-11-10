<?php
/**
 * Cadastro de Funcionários e Diaristas
 *
 * Acesso: Administrador, Recursos Humanos e Supervisão (esta, só na própria filial).
 * Ações (POST): cadastrar, editar e alterar_status (inativar guarda a data de inativação).
 *
 * Funcionário = quadro fixo de uma empresa e filial; Diarista = cobre faltas (substituição)
 * ou reforça a equipe (extra). Cargo e turno são obrigatórios: definem a coluna usada
 * na Tabela de Valores e ficam gravados em cada dia lançado.
 * A lista tem filtros por filial, tipo e situação (ativos, inativos, todos).
 */
require_once 'conexao.php';

$mensagem = '';

// Processa o envio do formulário de cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar'])) {
    $nome = trim($_POST['nome'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $tipo = ($_POST['tipo'] ?? '') === 'Diarista' ? 'Diarista' : 'Funcionario';
    $empresa_id = ($_POST['empresa_id'] ?? '') !== '' ? (int)$_POST['empresa_id'] : null;
    $carga_horaria = isset($_POST['carga_horaria']) && $_POST['carga_horaria'] !== '' ? (float)$_POST['carga_horaria'] : 8.00;
    // Cargo só para funcionário fixo; turno para todos (usados na Tabela de Valores)
    $cargo = isset(cargos()[$_POST['cargo'] ?? '']) ? $_POST['cargo'] : null;
    $turno = isset(turnos()[$_POST['turno'] ?? '']) ? $_POST['turno'] : null;
    // A supervisão só cadastra na própria filial
    $filial_id = filial_do_usuario() ?? (int)($_POST['filial_id'] ?? 0);

    // Funcionário fixo precisa de empresa; diarista pode ficar sem vínculo (atende qualquer empresa da filial)
    if ($filial_id <= 0) {
        $mensagem = alerta('warning', 'Escolha a filial do colaborador.');
    } elseif (!$turno || !$cargo) {
        $mensagem = alerta('warning', 'Escolha o cargo e o turno ' . ($tipo === 'Funcionario' ? 'do funcionário.' : 'da diarista.'));
    } elseif ($nome && $cpf && $carga_horaria > 0 && ($empresa_id || $tipo === 'Diarista')) {
        try {
            $sql_insert = "INSERT INTO funcionarios (empresa_id, filial_id, nome, cpf, carga_horaria, tipo, cargo, turno, status, data_cadastro) VALUES (:empresa_id, :filial_id, :nome, :cpf, :carga_horaria, :tipo, :cargo, :turno, 1, CURDATE())";
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([
                ':cargo' => $cargo,
                ':turno' => $turno,
                ':empresa_id' => $empresa_id,
                ':filial_id' => $filial_id,
                ':nome' => $nome,
                ':cpf' => $cpf,
                ':carga_horaria' => $carga_horaria,
                ':tipo' => $tipo
            ]);
            $rotulo = $tipo === 'Diarista' ? 'Diarista' : 'Funcionário';
            $mensagem = alerta('success', $rotulo . ' cadastrado com sucesso!');
        } catch (PDOException $e) {
            $mensagem = alerta('danger', 'Erro ao cadastrar: ' . e($e->getMessage()));
        }
    } else {
        $mensagem = alerta('warning', 'Preencha todos os campos obrigatórios. Funcionários fixos precisam de uma empresa vinculada.');
    }
}

// Edição do cadastro (nome, CPF, carga, tipo e empresa): somente o admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar'])) {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $tipo = ($_POST['tipo'] ?? '') === 'Diarista' ? 'Diarista' : 'Funcionario';
    $empresa_id = ($_POST['empresa_id'] ?? '') !== '' ? (int)$_POST['empresa_id'] : null;
    $carga_horaria = (float)($_POST['carga_horaria'] ?? 0);
    $filial_id = (int)($_POST['filial_id'] ?? 0);
    $cargo = isset(cargos()[$_POST['cargo'] ?? '']) ? $_POST['cargo'] : null;
    $turno = isset(turnos()[$_POST['turno'] ?? '']) ? $_POST['turno'] : null;

    if (!usuario_admin()) {
        $mensagem = alerta('danger', 'Somente o administrador pode editar o cadastro de colaboradores.');
    } elseif (!$id || !$nome || !$cpf || $carga_horaria <= 0 || $carga_horaria > 24 || (!$empresa_id && $tipo !== 'Diarista') || $filial_id <= 0) {
        $mensagem = alerta('warning', 'Preencha todos os campos obrigatórios, incluindo a filial. Funcionários fixos precisam de uma empresa vinculada.');
    } elseif (!$turno || !$cargo) {
        $mensagem = alerta('warning', 'Escolha o cargo e o turno ' . ($tipo === 'Funcionario' ? 'do funcionário.' : 'da diarista.'));
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE funcionarios SET nome = :nome, cpf = :cpf, carga_horaria = :carga_horaria, tipo = :tipo, cargo = :cargo, turno = :turno, empresa_id = :empresa_id, filial_id = :filial_id WHERE id = :id");
            $stmt->execute([
                ':cargo' => $cargo,
                ':turno' => $turno,
                ':filial_id' => $filial_id,
                ':nome' => $nome,
                ':cpf' => $cpf,
                ':carga_horaria' => $carga_horaria,
                ':tipo' => $tipo,
                ':empresa_id' => $empresa_id,
                ':id' => $id
            ]);
            $mensagem = alerta('success', 'Cadastro de ' . e($nome) . ' atualizado com sucesso!');
        } catch (PDOException $e) {
            $mensagem = alerta('danger', 'Erro ao atualizar: ' . e($e->getMessage()));
        }
    }
}

// Inativa ou reativa um colaborador (o histórico de frequência é mantido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $novo_status = (int)($_POST['novo_status'] ?? 0) === 1 ? 1 : 0;
    try {
        if (!pode_ver_funcionario($pdo, $id)) {
            throw new PDOException('colaborador de outra filial.');
        }
        $stmt = $pdo->prepare("UPDATE funcionarios SET status = :status, data_inativacao = :data WHERE id = :id");
        $stmt->execute([
            ':status' => $novo_status,
            ':data' => $novo_status === 1 ? null : date('Y-m-d'),
            ':id' => $id
        ]);
        $mensagem = alerta('success', 'Colaborador ' . ($novo_status === 1 ? 'reativado' : 'inativado') . ' com sucesso!');
    } catch (PDOException $e) {
        $mensagem = alerta('danger', 'Erro ao alterar status: ' . e($e->getMessage()));
    }
}

$filtro_status = $_GET['status'] ?? 'ativos';
$filtro_tipo = $_GET['tipo'] ?? '';
// "sem" lista quem ainda não tem filial (só para quem vê todas)
$filtro_filial = filial_do_usuario() ?? (($_GET['filial_id'] ?? '') === 'sem' ? 'sem' : (int)($_GET['filial_id'] ?? 0));

// Busca empresas e colaboradores cadastrados
try {
    $empresas = lista_empresas($pdo);
    $filiais = lista_filiais($pdo);

    $where = [];
    if ($filtro_filial === 'sem') {
        $where[] = 'f.filial_id IS NULL';
    } elseif ($filtro_filial) {
        $where[] = 'f.filial_id = ' . (int)$filtro_filial;
    }
    if ($filtro_status === 'ativos') {
        $where[] = 'f.status = 1';
    } elseif ($filtro_status === 'inativos') {
        $where[] = 'f.status = 0';
    }
    if ($filtro_tipo === 'Funcionario' || $filtro_tipo === 'Diarista') {
        $where[] = "f.tipo = " . $pdo->quote($filtro_tipo);
    }
    $sql_funcionarios = "SELECT f.*, e.razao_social, fi.nome AS filial FROM funcionarios f
                         LEFT JOIN empresas_terceirizadas e ON f.empresa_id = e.id
                         LEFT JOIN filiais fi ON fi.id = f.filial_id
                         " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
                         ORDER BY f.tipo ASC, f.nome ASC";
    $funcionarios = $pdo->query($sql_funcionarios)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $empresas = [];
    $filiais = [];
    $funcionarios = [];
}
$sem_filial = filial_do_usuario() === null ? (int)$pdo->query("SELECT COUNT(*) FROM funcionarios WHERE filial_id IS NULL AND status = 1")->fetchColumn() : 0;
$sem_cargo_turno = (int)$pdo->query("SELECT COUNT(*) FROM funcionarios WHERE status = 1 AND (turno IS NULL OR cargo IS NULL)" . sql_filial('filial_id', filial_do_usuario()))->fetchColumn();

render_cabecalho('Funcionários e Diaristas', 'Cadastro do quadro fixo e das diaristas que cobrem faltas (substituições).');
?>

<div class="container pb-5">
    <?php echo $mensagem; ?>

    <?php if ($sem_filial && $filtro_filial !== 'sem'): ?>
    <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-warning-soft); color: var(--app-warning); border: 1px solid #fedf89;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><strong><?php echo $sem_filial === 1 ? '1 colaborador ativo está' : $sem_filial . ' colaboradores ativos estão'; ?> sem filial.</strong> Eles não aparecem no lançamento de frequência até receberem uma filial. <a href="cadastro_funcionario.php?filial_id=sem" class="fw-semibold" style="color: inherit;">Ver quem está sem filial</a></div>
    </div>
    <?php endif; ?>

    <?php if ($sem_cargo_turno): ?>
    <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-warning-soft); color: var(--app-warning); border: 1px solid #fedf89;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><strong><?php echo $sem_cargo_turno === 1 ? '1 colaborador ativo está' : $sem_cargo_turno . ' colaboradores ativos estão'; ?> sem cargo ou turno.</strong> Sem eles, os dias trabalhados ficam sem valor na pré-fatura.<?php echo usuario_admin() ? ' Use o lápis (editar) na lista para completar.' : ' Peça ao administrador para completar o cadastro.'; ?></div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-3 col-lg-4">
            <div class="card card-custom p-4">
                <h5 class="mb-4"><i class="bi bi-person-plus me-2 text-muted"></i>Novo colaborador</h5>
                <form action="cadastro_funcionario.php" method="POST">
                    <input type="hidden" name="cadastrar" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tipo</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="tipo" id="tipo_func" value="Funcionario" checked onchange="ajustarTipo()">
                            <label class="btn btn-outline-primary" for="tipo_func">Funcionário</label>
                            <input type="radio" class="btn-check" name="tipo" id="tipo_diar" value="Diarista" onchange="ajustarTipo()">
                            <label class="btn btn-outline-primary" for="tipo_diar">Diarista</label>
                        </div>
                        <small class="text-muted" id="ajuda_tipo">Funcionário fixo do quadro da empresa.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Filial</label>
                        <?php render_campo_filial($filiais, filial_do_usuario() ?? 0, 'filial_id', 'Selecione...', 'form-select', ' required'); ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome Completo</label>
                        <input type="text" name="nome" class="form-control" required placeholder="Ex: João Silva">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">CPF</label>
                        <input type="text" name="cpf" class="form-control" required placeholder="000.000.000-00">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12" id="bloco_cargo">
                            <label class="form-label fw-semibold">Cargo</label>
                            <select name="cargo" id="cargo" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach (cargos() as $valor_cargo => $rotulo_cargo): ?>
                                    <option value="<?php echo $valor_cargo; ?>"><?php echo e($rotulo_cargo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Turno</label>
                            <select name="turno" class="form-select" required>
                                <option value="">...</option>
                                <?php foreach (turnos() as $valor_turno => $rotulo_turno): ?>
                                    <option value="<?php echo $valor_turno; ?>"><?php echo e($rotulo_turno); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="text-muted">Usados no valor da pré-fatura (Tabela de Valores).</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Carga horária diária</label>
                        <input type="number" name="carga_horaria" class="form-control" required min="1" max="24" step="0.5" value="8" placeholder="Ex: 8">
                        <small class="text-muted">Essa carga será usada para calcular horas extras.</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Vínculo Empresarial</label>
                        <select name="empresa_id" id="empresa_id" class="form-select" required>
                            <option value="" id="opcao_sem_vinculo">Selecione...</option>
                            <?php foreach ($empresas as $emp): if ((int)$emp['status'] !== 1) continue; // empresa inativa não recebe novos cadastros ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo e($emp['razao_social']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-check2"></i>Salvar cadastro</button>
                </form>
            </div>
        </div>

        <div class="col-xl-9 col-lg-8">
            <div class="card card-custom overflow-hidden">
                <div class="p-3 px-4 border-bottom d-flex flex-wrap gap-3 justify-content-between align-items-center">
                    <h5 class="m-0">Colaboradores <span class="text-muted fw-normal small">(<?php echo count($funcionarios); ?>)</span></h5>
                    <form method="GET" class="d-flex flex-wrap gap-2">
                        <?php if (filial_do_usuario() === null): ?>
                        <select name="filial_id" class="form-select form-select-sm" style="min-width: 150px" onchange="this.form.submit()">
                            <option value="">Todas as filiais</option>
                            <?php foreach ($filiais as $fil): ?>
                                <option value="<?php echo (int)$fil['id']; ?>" <?php echo $filtro_filial === (int)$fil['id'] ? 'selected' : ''; ?>><?php echo e($fil['nome']); ?></option>
                            <?php endforeach; ?>
                            <option value="sem" <?php echo $filtro_filial === 'sem' ? 'selected' : ''; ?>>Sem filial</option>
                        </select>
                        <?php endif; ?>
                        <select name="tipo" class="form-select form-select-sm" style="min-width: 150px" onchange="this.form.submit()">
                            <option value="" <?php echo $filtro_tipo === '' ? 'selected' : ''; ?>>Todos os tipos</option>
                            <option value="Funcionario" <?php echo $filtro_tipo === 'Funcionario' ? 'selected' : ''; ?>>Funcionários</option>
                            <option value="Diarista" <?php echo $filtro_tipo === 'Diarista' ? 'selected' : ''; ?>>Diaristas</option>
                        </select>
                        <select name="status" class="form-select form-select-sm" style="min-width: 110px" onchange="this.form.submit()">
                            <option value="ativos" <?php echo $filtro_status === 'ativos' ? 'selected' : ''; ?>>Ativos</option>
                            <option value="inativos" <?php echo $filtro_status === 'inativos' ? 'selected' : ''; ?>>Inativos</option>
                            <option value="todos" <?php echo $filtro_status === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 tabela-cadastro">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Filial</th>
                                <th>Cargo · Turno</th>
                                <th>CPF</th>
                                <th>Empresa Parceira</th>
                                <th class="num">Carga</th>
                                <th class="text-center">Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($funcionarios) > 0): ?>
                                <?php foreach ($funcionarios as $func): $ativo = (int)$func['status'] === 1; ?>
                                <tr class="<?php echo $ativo ? '' : 'text-muted'; ?>">
                                    <td class="fw-medium"><?php echo e($func['nome']); ?></td>
                                    <td>
                                        <?php if ($func['tipo'] === 'Diarista'): ?>
                                            <span class="badge-app badge-warning">Diarista</span>
                                        <?php else: ?>
                                            <span class="badge-app badge-primary">Funcionário</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $func['filial'] ? e($func['filial']) : '<span class="badge-app badge-warning">Sem filial</span>'; ?></td>
                                    <td class="text-nowrap">
                                            <?php echo $func['cargo'] ? e(cargos()[$func['cargo']]) : '<span class="badge-app badge-warning">Sem cargo</span>'; ?> ·
                                        <?php echo $func['turno'] ? e($func['turno']) : '<span class="badge-app badge-warning">Sem turno</span>'; ?>
                                    </td>
                                    <td class="text-muted"><?php echo e($func['cpf']); ?></td>
                                    <td><?php echo $func['razao_social'] ? e($func['razao_social']) : '<span class="text-muted fst-italic">Todas</span>'; ?></td>
                                    <td class="num text-muted"><?php echo hhmm($func['carga_horaria'] ?? 8); ?></td>
                                    <td class="text-center">
                                        <?php if ($ativo): ?>
                                            <span class="badge-app badge-success">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge-app badge-neutral" title="Inativado em <?php echo $func['data_inativacao'] ? date('d/m/Y', strtotime($func['data_inativacao'])) : '-'; ?>">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (usuario_admin()): ?>
                                        <button type="button" class="btn btn-sm btn-light-app" title="Editar cadastro"
                                                data-bs-toggle="modal" data-bs-target="#modalEditar"
                                                data-id="<?php echo (int)$func['id']; ?>"
                                                data-nome="<?php echo e($func['nome']); ?>"
                                                data-cpf="<?php echo e($func['cpf']); ?>"
                                                data-carga="<?php echo e((float)$func['carga_horaria']); ?>"
                                                data-tipo="<?php echo e($func['tipo']); ?>"
                                                data-cargo="<?php echo e($func['cargo'] ?? ''); ?>"
                                                data-turno="<?php echo e($func['turno'] ?? ''); ?>"
                                                data-empresa="<?php echo e($func['empresa_id'] ?? ''); ?>"
                                                data-filial="<?php echo e($func['filial_id'] ?? ''); ?>"
                                                onclick="abrirEdicao(this)"><i class="bi bi-pencil me-0"></i></button>
                                        <?php endif; ?>
                                        <form method="POST" action="cadastro_funcionario.php?<?php echo e(http_build_query($_GET)); ?>" class="d-inline"
                                              onsubmit="return confirm('<?php echo $ativo ? 'Inativar' : 'Reativar'; ?> <?php echo e(addslashes($func['nome'])); ?>?');">
                                            <input type="hidden" name="alterar_status" value="1">
                                            <input type="hidden" name="id" value="<?php echo $func['id']; ?>">
                                            <input type="hidden" name="novo_status" value="<?php echo $ativo ? 0 : 1; ?>">
                                            <?php if ($ativo): ?>
                                                <button type="submit" class="btn btn-sm btn-light-app text-danger" title="Inativar"><i class="bi bi-person-dash me-0"></i></button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-sm btn-light-app text-success" title="Reativar"><i class="bi bi-person-check me-0"></i></button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="table-empty"><i class="bi bi-people"></i>Nenhum colaborador encontrado com esse filtro.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (usuario_admin()): ?>
<!-- Edição do cadastro (somente admin) -->
<div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="cadastro_funcionario.php?<?php echo e(http_build_query($_GET)); ?>" class="modal-content">
            <input type="hidden" name="editar" value="1">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarTitulo"><i class="bi bi-pencil-square me-2 text-muted"></i>Editar colaborador</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Tipo</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="tipo" id="edit_tipo_func" value="Funcionario" onchange="ajustarTipoEdicao()">
                        <label class="btn btn-outline-primary" for="edit_tipo_func">Funcionário</label>
                        <input type="radio" class="btn-check" name="tipo" id="edit_tipo_diar" value="Diarista" onchange="ajustarTipoEdicao()">
                        <label class="btn btn-outline-primary" for="edit_tipo_diar">Diarista</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Filial</label>
                    <select name="filial_id" id="edit_filial" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($filiais as $fil): ?>
                            <option value="<?php echo (int)$fil['id']; ?>"><?php echo e($fil['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nome Completo</label>
                    <input type="text" name="nome" id="edit_nome" class="form-control" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-7" id="edit_bloco_cargo">
                        <label class="form-label fw-semibold">Cargo</label>
                        <select name="cargo" id="edit_cargo" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach (cargos() as $valor_cargo => $rotulo_cargo): ?>
                                <option value="<?php echo $valor_cargo; ?>"><?php echo e($rotulo_cargo); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold">Turno</label>
                        <select name="turno" id="edit_turno" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach (turnos() as $valor_turno => $rotulo_turno): ?>
                                <option value="<?php echo $valor_turno; ?>"><?php echo e($rotulo_turno); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-sm-7">
                        <label class="form-label fw-semibold">CPF</label>
                        <input type="text" name="cpf" id="edit_cpf" class="form-control" required>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold">Carga diária (h)</label>
                        <input type="number" name="carga_horaria" id="edit_carga" class="form-control" required min="1" max="24" step="0.5">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Vínculo Empresarial</label>
                    <select name="empresa_id" id="edit_empresa" class="form-select">
                        <option value="" id="edit_sem_vinculo">Selecione...</option>
                        <?php foreach ($empresas as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo e($emp['razao_social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>A nova carga vale para os cálculos de todos os relatórios, inclusive dos dias já lançados.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar alterações</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
    .tabela-cadastro td:not(:first-child):not(:nth-child(5)) { white-space: nowrap; }
    .tabela-cadastro td:first-child { min-width: 150px; }
</style>

<script>
// Diarista pode ficar sem empresa (atende todas); funcionário fixo precisa de vínculo
function ajustarTipo() {
    const diarista = document.getElementById('tipo_diar').checked;
    const select = document.getElementById('empresa_id');
    select.required = !diarista;
    document.getElementById('opcao_sem_vinculo').textContent = diarista ? 'Todas as empresas (avulsa)' : 'Selecione...';
    document.getElementById('ajuda_tipo').textContent = diarista
        ? 'Diarista que cobre faltas do quadro (substituição) na filial. Pode ficar sem empresa fixa.'
        : 'Funcionário fixo do quadro da empresa.';
}

// Preenche a janela de edição com os dados da linha
function abrirEdicao(botao) {
    const d = botao.dataset;
    document.getElementById('edit_id').value = d.id;
    document.getElementById('edit_nome').value = d.nome;
    document.getElementById('edit_cpf').value = d.cpf;
    document.getElementById('edit_carga').value = d.carga;
    document.getElementById('edit_empresa').value = d.empresa;
    document.getElementById('edit_filial').value = d.filial;
    document.getElementById('edit_cargo').value = d.cargo;
    document.getElementById('edit_turno').value = d.turno;
    document.getElementById(d.tipo === 'Diarista' ? 'edit_tipo_diar' : 'edit_tipo_func').checked = true;
    ajustarTipoEdicao();
}

function ajustarTipoEdicao() {
    const diarista = document.getElementById('edit_tipo_diar').checked;
    document.getElementById('edit_empresa').required = !diarista;
    document.getElementById('edit_sem_vinculo').textContent = diarista ? 'Todas as empresas (avulsa)' : 'Selecione...';
}
</script>

<?php render_rodape(); ?>
