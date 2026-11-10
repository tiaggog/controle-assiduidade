<?php
/**
 * Cadastro de Empresas (clientes terceirizadas)
 *
 * Acesso: os mesmos perfis que cadastram funcionários (Administrador, Recursos Humanos e Supervisão).
 * Ações (POST): cadastrar e alterar_status (ativar/inativar); editar (razão social e CNPJ) só o Administrador.
 *
 * Regras:
 *  - CNPJ obrigatório, válido (dígitos verificadores) e único; gravado no formato 00.000.000/0000-00;
 *  - Só inativa a empresa que não tem funcionário ativo vinculado (transfira ou inative antes);
 *  - Empresa inativa some do formulário de novos colaboradores, mas o histórico continua nos relatórios.
 */
require_once 'conexao.php';

$mensagem = '';

// CNPJ com 14 dígitos e dígitos verificadores corretos; devolve formatado ou null
function cnpj_valido($cnpj) {
    $n = preg_replace('/\D/', '', (string)$cnpj);
    if (strlen($n) !== 14 || preg_match('/^(\d)\1{13}$/', $n)) {
        return null;
    }
    foreach ([12, 13] as $tam) {
        $pesos = $tam === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < $tam; $i++) {
            $soma += (int)$n[$i] * $pesos[$i];
        }
        $digito = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ((int)$n[$tam] !== $digito) {
            return null;
        }
    }
    return substr($n, 0, 2) . '.' . substr($n, 2, 3) . '.' . substr($n, 5, 3) . '/' . substr($n, 8, 4) . '-' . substr($n, 12, 2);
}

// Valida razão social e CNPJ (único); devolve [razao, cnpj, erro]
function validar_empresa(PDO $pdo, $id = 0) {
    $razao = trim(preg_replace('/\s+/', ' ', $_POST['razao_social'] ?? ''));
    $cnpj = cnpj_valido($_POST['cnpj'] ?? '');
    if ($razao === '') {
        return [null, null, 'Informe a razão social.'];
    }
    if (!$cnpj) {
        return [null, null, 'CNPJ inválido. Confira os 14 números.'];
    }
    $stmt = $pdo->prepare("SELECT razao_social FROM empresas_terceirizadas WHERE cnpj = :cnpj AND id <> :id");
    $stmt->execute([':cnpj' => $cnpj, ':id' => $id]);
    if ($outra = $stmt->fetchColumn()) {
        return [null, null, 'Este CNPJ já está cadastrado para ' . e($outra) . '.'];
    }
    return [mb_substr($razao, 0, 255), $cnpj, null];
}

// Nova empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar'])) {
    [$razao, $cnpj, $erro] = validar_empresa($pdo);
    if ($erro) {
        $mensagem = alerta('warning', $erro);
    } else {
        $pdo->prepare("INSERT INTO empresas_terceirizadas (razao_social, cnpj, status) VALUES (:razao, :cnpj, 1)")
            ->execute([':razao' => $razao, ':cnpj' => $cnpj]);
        $mensagem = alerta('success', 'Empresa ' . e($razao) . ' cadastrada com sucesso!');
    }
}

// Editar razão social e CNPJ (somente o admin, como no cadastro de funcionários)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar'])) {
    $id = (int)($_POST['id'] ?? 0);
    if (!usuario_admin()) {
        $mensagem = alerta('danger', 'Somente o administrador pode editar o cadastro de empresas.');
    } else {
        [$razao, $cnpj, $erro] = validar_empresa($pdo, $id);
        if ($erro) {
            $mensagem = alerta('warning', $erro);
        } else {
            $pdo->prepare("UPDATE empresas_terceirizadas SET razao_social = :razao, cnpj = :cnpj WHERE id = :id")
                ->execute([':razao' => $razao, ':cnpj' => $cnpj, ':id' => $id]);
            $mensagem = alerta('success', 'Empresa atualizada.');
        }
    }
}

// Ativar / inativar: só inativa sem funcionário ativo vinculado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_status'])) {
    $id = (int)($_POST['id'] ?? 0);
    $novo = (int)($_POST['novo_status'] ?? 0) === 1 ? 1 : 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM funcionarios WHERE empresa_id = :id AND status = 1");
    $stmt->execute([':id' => $id]);
    $ativos = (int)$stmt->fetchColumn();
    if ($novo === 0 && $ativos > 0) {
        $mensagem = alerta('warning', 'Esta empresa ainda tem ' . ($ativos === 1 ? '1 colaborador ativo' : $ativos . ' colaboradores ativos') . '. Transfira ou inative os colaboradores antes de inativar a empresa.');
    } else {
        $pdo->prepare("UPDATE empresas_terceirizadas SET status = :status WHERE id = :id")->execute([':status' => $novo, ':id' => $id]);
        $mensagem = alerta('success', $novo ? 'Empresa reativada.' : 'Empresa inativada.');
    }
}

$empresas = $pdo->query("SELECT e.id, e.razao_social, e.cnpj, e.status,
                                (SELECT COUNT(*) FROM funcionarios f WHERE f.empresa_id = e.id AND f.status = 1 AND f.tipo = 'Funcionario') AS funcionarios,
                                (SELECT COUNT(*) FROM funcionarios f WHERE f.empresa_id = e.id AND f.status = 1 AND f.tipo = 'Diarista') AS diaristas
                         FROM empresas_terceirizadas e ORDER BY e.status DESC, e.razao_social ASC")->fetchAll(PDO::FETCH_ASSOC);

render_cabecalho('Empresas', 'Empresas clientes atendidas. Cada funcionário do quadro fica vinculado a uma empresa, que define a folha de frequência e a pré-fatura.');
?>

<div class="container pb-5">
    <?php echo $mensagem; ?>

    <div class="row g-4">
        <div class="col-xl-3 col-lg-4">
            <div class="card card-custom p-4">
                <h5 class="mb-4"><i class="bi bi-briefcase me-2 text-muted"></i>Nova empresa</h5>
                <form method="POST" action="cadastro_empresa.php">
                    <input type="hidden" name="cadastrar" value="1">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="razao_social">Razão social</label>
                        <input type="text" name="razao_social" id="razao_social" class="form-control" maxlength="255" required placeholder="Ex: Empresa Exemplo Ltda">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="cnpj">CNPJ</label>
                        <input type="text" name="cnpj" id="cnpj" class="form-control js-cnpj" inputmode="numeric" maxlength="18" required placeholder="00.000.000/0000-00">
                    </div>
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-check2"></i>Salvar empresa</button>
                </form>
            </div>
        </div>

        <div class="col-xl-9 col-lg-8">
            <div class="card card-custom overflow-hidden">
                <div class="p-3 px-4 border-bottom">
                    <h5 class="m-0">Empresas <span class="text-muted fw-normal small">(<?php echo count($empresas); ?>)</span></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Razão social</th>
                                <th>CNPJ</th>
                                <th class="text-center">Funcionários</th>
                                <th class="text-center">Diaristas</th>
                                <th class="text-center">Status</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$empresas): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma empresa cadastrada.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($empresas as $emp): $ativa = (int)$emp['status'] === 1; ?>
                            <tr class="<?php echo $ativa ? '' : 'text-muted'; ?>">
                                <td class="fw-medium"><i class="bi bi-briefcase me-1 text-muted"></i><?php echo e($emp['razao_social']); ?></td>
                                <td class="text-nowrap"><?php echo e($emp['cnpj']); ?></td>
                                <td class="text-center"><?php echo (int)$emp['funcionarios']; ?></td>
                                <td class="text-center"><?php echo (int)$emp['diaristas']; ?></td>
                                <td class="text-center">
                                    <?php if ($ativa): ?>
                                        <span class="badge-app badge-success">Ativa</span>
                                    <?php else: ?>
                                        <span class="badge-app badge-neutral">Inativa</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if (usuario_admin()): ?>
                                    <button type="button" class="btn btn-sm btn-light-app" title="Editar"
                                            data-bs-toggle="modal" data-bs-target="#modalEditar"
                                            data-id="<?php echo (int)$emp['id']; ?>"
                                            data-razao="<?php echo e($emp['razao_social']); ?>"
                                            data-cnpj="<?php echo e($emp['cnpj']); ?>"
                                            onclick="document.getElementById('editar_id').value = this.dataset.id; document.getElementById('editar_razao').value = this.dataset.razao; document.getElementById('editar_cnpj').value = this.dataset.cnpj;"><i class="bi bi-pencil me-0"></i></button>
                                    <?php endif; ?>
                                    <form method="POST" action="cadastro_empresa.php" class="d-inline"
                                          onsubmit="return confirm(this.dataset.pergunta);"
                                          data-pergunta="<?php echo e(($ativa ? 'Inativar' : 'Reativar') . ' a empresa ' . $emp['razao_social'] . '?'); ?>">
                                        <input type="hidden" name="alterar_status" value="1">
                                        <input type="hidden" name="id" value="<?php echo (int)$emp['id']; ?>">
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

<?php if (usuario_admin()): ?>
<div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="cadastro_empresa.php" class="modal-content">
            <input type="hidden" name="editar" value="1">
            <input type="hidden" name="id" id="editar_id">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditarTitulo"><i class="bi bi-pencil-square me-2 text-muted"></i>Editar empresa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="editar_razao">Razão social</label>
                    <input type="text" name="razao_social" id="editar_razao" class="form-control" maxlength="255" required>
                </div>
                <label class="form-label fw-semibold" for="editar_cnpj">CNPJ</label>
                <input type="text" name="cnpj" id="editar_cnpj" class="form-control js-cnpj" inputmode="numeric" maxlength="18" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Máscara do CNPJ enquanto digita: 00.000.000/0000-00
document.querySelectorAll('.js-cnpj').forEach(function (campo) {
    campo.addEventListener('input', function () {
        const n = campo.value.replace(/\D/g, '').slice(0, 14);
        let v = n.slice(0, 2);
        if (n.length > 2) v += '.' + n.slice(2, 5);
        if (n.length > 5) v += '.' + n.slice(5, 8);
        if (n.length > 8) v += '/' + n.slice(8, 12);
        if (n.length > 12) v += '-' + n.slice(12, 14);
        campo.value = v;
    });
});
</script>

<?php render_rodape(); ?>
