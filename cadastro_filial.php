<?php
require_once 'conexao.php';

$mensagem = '';

// Nova filial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar'])) {
    $nome = mb_strtoupper(trim($_POST['nome'] ?? ''));
    if ($nome === '') {
        $mensagem = alerta('warning', 'Informe o nome da filial.');
    } else {
        try {
            $pdo->prepare("INSERT INTO filiais (nome) VALUES (:nome)")->execute([':nome' => mb_substr($nome, 0, 60)]);
            $mensagem = alerta('success', 'Filial ' . e($nome) . ' cadastrada com sucesso!');
        } catch (PDOException $e) {
            $mensagem = alerta('danger', $e->getCode() === '23000' ? 'Já existe uma filial com esse nome.' : 'Erro ao cadastrar: ' . e($e->getMessage()));
        }
    }
}

// Renomear filial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar'])) {
    $id = (int)($_POST['id'] ?? 0);
    $nome = mb_strtoupper(trim($_POST['nome'] ?? ''));
    if (!$id || $nome === '') {
        $mensagem = alerta('warning', 'Informe o nome da filial.');
    } else {
        try {
            $pdo->prepare("UPDATE filiais SET nome = :nome WHERE id = :id")->execute([':nome' => mb_substr($nome, 0, 60), ':id' => $id]);
            $mensagem = alerta('success', 'Filial atualizada para ' . e($nome) . '.');
        } catch (PDOException $e) {
            $mensagem = alerta('danger', $e->getCode() === '23000' ? 'Já existe uma filial com esse nome.' : 'Erro ao salvar: ' . e($e->getMessage()));
        }
    }
}

// Ativar / inativar (filial inativa não aparece para novos cadastros nem na frequência)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_status'])) {
    $pdo->prepare("UPDATE filiais SET status = :status WHERE id = :id")
        ->execute([':status' => (int)($_POST['novo_status'] ?? 0) === 1 ? 1 : 0, ':id' => (int)($_POST['id'] ?? 0)]);
    $mensagem = alerta('success', 'Situação da filial atualizada.');
}

$filiais = $pdo->query("SELECT fi.id, fi.nome, fi.status,
                               (SELECT COUNT(*) FROM funcionarios f WHERE f.filial_id = fi.id AND f.status = 1 AND f.tipo = 'Funcionario') AS funcionarios,
                               (SELECT COUNT(*) FROM funcionarios f WHERE f.filial_id = fi.id AND f.status = 1 AND f.tipo = 'Diarista') AS diaristas,
                               (SELECT COUNT(*) FROM usuarios u WHERE u.filial_id = fi.id AND u.status = 1) AS usuarios
                        FROM filiais fi ORDER BY fi.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$sem_filial = (int)$pdo->query("SELECT COUNT(*) FROM funcionarios WHERE filial_id IS NULL AND status = 1")->fetchColumn();

render_cabecalho('Filiais', 'Cada colaborador pertence a uma filial. A supervisão só vê as informações da própria filial; admin, financeiro e gestão veem todas.');
?>

<div class="container pb-5">
    <?php echo $mensagem; ?>

    <?php if ($sem_filial): ?>
    <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-warning-soft); color: var(--app-warning); border: 1px solid #fedf89;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><strong><?php echo $sem_filial === 1 ? '1 colaborador ativo está' : $sem_filial . ' colaboradores ativos estão'; ?> sem filial.</strong> Eles não aparecem no lançamento de frequência até receberem uma filial. <a href="cadastro_funcionario.php?filial_id=sem" class="fw-semibold" style="color: inherit;">Definir filial</a></div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-3 col-lg-4">
            <div class="card card-custom p-4">
                <h5 class="mb-4"><i class="bi bi-building-add me-2 text-muted"></i>Nova filial</h5>
                <form method="POST" action="cadastro_filial.php">
                    <input type="hidden" name="cadastrar" value="1">
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Nome da filial</label>
                        <input type="text" name="nome" class="form-control text-uppercase" maxlength="60" required placeholder="Ex: FORTALEZA">
                    </div>
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-check2"></i>Salvar filial</button>
                </form>
            </div>
        </div>

        <div class="col-xl-9 col-lg-8">
            <div class="card card-custom overflow-hidden">
                <div class="p-3 px-4 border-bottom">
                    <h5 class="m-0">Filiais <span class="text-muted fw-normal small">(<?php echo count($filiais); ?>)</span></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Filial</th>
                                <th class="text-center">Funcionários</th>
                                <th class="text-center">Diaristas</th>
                                <th class="text-center">Usuários</th>
                                <th class="text-center">Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filiais as $fil): $ativa = (int)$fil['status'] === 1; ?>
                            <tr class="<?php echo $ativa ? '' : 'text-muted'; ?>">
                                <td class="fw-medium"><i class="bi bi-geo-alt me-1 text-muted"></i><?php echo e($fil['nome']); ?></td>
                                <td class="text-center"><a href="cadastro_funcionario.php?filial_id=<?php echo (int)$fil['id']; ?>&tipo=Funcionario"><?php echo (int)$fil['funcionarios']; ?></a></td>
                                <td class="text-center"><a href="cadastro_funcionario.php?filial_id=<?php echo (int)$fil['id']; ?>&tipo=Diarista"><?php echo (int)$fil['diaristas']; ?></a></td>
                                <td class="text-center"><?php echo (int)$fil['usuarios']; ?></td>
                                <td class="text-center">
                                    <?php if ($ativa): ?>
                                        <span class="badge-app badge-success">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge-app badge-neutral">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-light-app" title="Renomear"
                                            data-bs-toggle="modal" data-bs-target="#modalEditar"
                                            onclick="document.getElementById('editar_id').value = '<?php echo (int)$fil['id']; ?>'; document.getElementById('editar_nome').value = this.dataset.nome;"
                                            data-nome="<?php echo e($fil['nome']); ?>"><i class="bi bi-pencil me-0"></i></button>
                                    <form method="POST" action="cadastro_filial.php" class="d-inline"
                                          onsubmit="return confirm('<?php echo $ativa ? 'Inativar' : 'Reativar'; ?> a filial <?php echo e(addslashes($fil['nome'])); ?>?');">
                                        <input type="hidden" name="alterar_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo (int)$fil['id']; ?>">
                                        <input type="hidden" name="novo_status" value="<?php echo $ativa ? 0 : 1; ?>">
                                        <?php if ($ativa): ?>
                                            <button type="submit" class="btn btn-sm btn-light-app text-danger" title="Inativar"><i class="bi bi-slash-circle me-0"></i></button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-light-app text-success" title="Reativar"><i class="bi bi-check-circle me-0"></i></button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="cadastro_filial.php" class="modal-content">
            <input type="hidden" name="editar" value="1">
            <input type="hidden" name="id" id="editar_id">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarTitulo"><i class="bi bi-pencil-square me-2 text-muted"></i>Renomear filial</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="editar_nome">Nome da filial</label>
                <input type="text" name="nome" id="editar_nome" class="form-control text-uppercase" maxlength="60" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar</button>
            </div>
        </form>
    </div>
</div>

<?php render_rodape(); ?>
