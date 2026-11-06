<?php
/**
 * Usuários e Perfis
 *
 * Acesso: somente Administrador.
 * Ações (POST): criar, editar (nome, perfil e filial), senha (redefinir) e status (ativar/desativar).
 * Perfis: Administrador, Recursos Humanos, Supervisão (presa a uma filial), Gestão e Financeiro.
 * Proteções: sempre sobra ao menos um administrador ativo e ninguém altera o próprio perfil.
 * A tabela "Permissões por perfil" é montada a partir de grupos_por_perfil() em funcoes.php.
 */
require_once 'conexao.php';

$mensagem = '';
$eu = usuario_atual();
$perfis = perfis();

function login_valido($login) {
    return (bool)preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $login);
}

// Garante que sempre sobre ao menos um administrador ativo
function restaria_admin(PDO $pdo, $id_alterado) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND status = 1 AND id <> :id");
    $stmt->execute([':id' => $id_alterado]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $perfil = $_POST['perfil'] ?? '';
    $senha = (string)($_POST['senha'] ?? '');
    // Só a supervisão fica presa a uma filial; os demais perfis veem todas
    $filial_id = $perfil === 'supervisao' ? (int)($_POST['filial_id'] ?? 0) : null;

    try {
        if ($acao === 'criar') {
            if ($nome === '' || !login_valido($login) || !isset($perfis[$perfil])) {
                throw new Exception('Preencha nome, perfil e um usuário com 3 a 50 caracteres (letras, números, ponto, hífen ou sublinhado).');
            }
            if ($perfil === 'supervisao' && !$filial_id) {
                throw new Exception('Escolha a filial do usuário de supervisão.');
            }
            if (strlen($senha) < 8) {
                throw new Exception('A senha precisa ter pelo menos 8 caracteres.');
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE login = :login");
            $stmt->execute([':login' => $login]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Já existe um usuário com esse login.');
            }
            $pdo->prepare("INSERT INTO usuarios (nome, login, senha_hash, perfil, filial_id) VALUES (:nome, :login, :hash, :perfil, :filial_id)")
                ->execute([':nome' => $nome, ':login' => $login, ':hash' => password_hash($senha, PASSWORD_DEFAULT), ':perfil' => $perfil, ':filial_id' => $filial_id]);
            $mensagem = alerta('success', 'Usuário <strong>' . e($login) . '</strong> criado com sucesso.');
        }

        if ($acao === 'editar') {
            if ($nome === '' || !isset($perfis[$perfil])) {
                throw new Exception('Informe o nome e o perfil.');
            }
            if ($perfil === 'supervisao' && !$filial_id) {
                throw new Exception('Escolha a filial do usuário de supervisão.');
            }
            if ($id === (int)$eu['id'] && $perfil !== $eu['perfil']) {
                throw new Exception('Você não pode alterar o seu próprio perfil.');
            }
            if ($perfil !== 'admin' && !restaria_admin($pdo, $id)) {
                throw new Exception('O sistema precisa de pelo menos um administrador ativo.');
            }
            $pdo->prepare("UPDATE usuarios SET nome = :nome, perfil = :perfil, filial_id = :filial_id WHERE id = :id")
                ->execute([':nome' => $nome, ':perfil' => $perfil, ':filial_id' => $filial_id, ':id' => $id]);
            $mensagem = alerta('success', 'Usuário atualizado com sucesso.');
        }

        if ($acao === 'senha') {
            if (strlen($senha) < 8) {
                throw new Exception('A nova senha precisa ter pelo menos 8 caracteres.');
            }
            $pdo->prepare("UPDATE usuarios SET senha_hash = :hash WHERE id = :id")
                ->execute([':hash' => password_hash($senha, PASSWORD_DEFAULT), ':id' => $id]);
            $mensagem = alerta('success', 'Senha redefinida com sucesso.');
        }

        if ($acao === 'status') {
            $novo_status = (int)($_POST['novo_status'] ?? 0) === 1 ? 1 : 0;
            if ($novo_status === 0 && $id === (int)$eu['id']) {
                throw new Exception('Você não pode inativar o seu próprio usuário.');
            }
            if ($novo_status === 0 && !restaria_admin($pdo, $id)) {
                throw new Exception('O sistema precisa de pelo menos um administrador ativo.');
            }
            $pdo->prepare("UPDATE usuarios SET status = :status WHERE id = :id")
                ->execute([':status' => $novo_status, ':id' => $id]);
            $mensagem = alerta('success', 'Usuário ' . ($novo_status ? 'reativado' : 'inativado') . ' com sucesso.');
        }
    } catch (Exception $ex) {
        $mensagem = alerta('danger', e($ex->getMessage()));
    }
}

$usuarios = $pdo->query("SELECT u.id, u.nome, u.login, u.perfil, u.filial_id, fi.nome AS filial, u.status, u.criado_em, u.ultimo_acesso
                         FROM usuarios u LEFT JOIN filiais fi ON fi.id = u.filial_id ORDER BY u.status DESC, u.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$filiais = lista_filiais($pdo);
$cores_perfil = ['admin' => 'badge-primary', 'financeiro' => 'badge-success', 'supervisao' => 'badge-warning', 'gestao' => 'badge-neutral', 'rh' => 'badge-danger'];

render_cabecalho('Usuários', 'Cadastro de usuários e perfis de acesso ao sistema.');
?>

<?php echo $mensagem; ?>

<div class="row g-4">
    <div class="col-xl-3 col-lg-4">
        <div class="card card-custom p-4 mb-4">
            <h5 class="mb-4"><i class="bi bi-person-plus me-2 text-muted"></i>Novo usuário</h5>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="acao" value="criar">
                <div class="mb-3">
                    <label class="form-label">Nome completo</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Usuário (login)</label>
                    <input type="text" name="login" class="form-control" pattern="[a-zA-Z0-9._\-]{3,50}" placeholder="ex.: maria.souza" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Senha inicial</label>
                    <input type="password" name="senha" class="form-control" minlength="8" autocomplete="new-password" required>
                    <small class="text-muted">Mínimo de 8 caracteres.</small>
                </div>
                <div class="mb-4">
                    <label class="form-label">Perfil</label>
                    <select name="perfil" class="form-select js-perfil" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($perfis as $chave => $rotulo): ?>
                            <option value="<?php echo $chave; ?>"><?php echo e($rotulo); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4 js-campo-filial" style="display:none">
                    <label class="form-label">Filial</label>
                    <select name="filial_id" class="form-select">
                        <option value="">Selecione...</option>
                        <?php foreach ($filiais as $fil): ?>
                            <option value="<?php echo (int)$fil['id']; ?>"><?php echo e($fil['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">A supervisão só vê as informações desta filial.</small>
                </div>
                <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-check2"></i>Criar usuário</button>
            </form>
        </div>
    </div>

    <div class="col-xl-9 col-lg-8">
        <div class="card card-custom overflow-hidden mb-4">
            <div class="p-3 px-4 border-bottom">
                <h5 class="m-0">Usuários <span class="text-muted fw-normal small">(<?php echo count($usuarios); ?>)</span></h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Usuário</th>
                            <th>Perfil</th>
                            <th>Status</th>
                            <th>Último acesso</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): $ativo = (int)$u['status'] === 1; $sou_eu = (int)$u['id'] === (int)$eu['id']; ?>
                        <tr class="<?php echo $ativo ? '' : 'text-muted'; ?>">
                            <td class="fw-medium"><?php echo e($u['nome']); ?><?php echo $sou_eu ? ' <span class="text-muted small">(você)</span>' : ''; ?></td>
                            <td class="text-muted"><?php echo e($u['login']); ?></td>
                            <td><span class="badge-app <?php echo $cores_perfil[$u['perfil']]; ?>"><?php echo e($perfis[$u['perfil']]); ?></span><?php if ($u['perfil'] === 'supervisao'): ?><div class="small mt-1 <?php echo $u['filial'] ? 'text-muted' : 'text-danger fw-semibold'; ?>"><i class="bi bi-geo-alt me-1"></i><?php echo $u['filial'] ? e($u['filial']) : 'Sem filial: não vê nada'; ?></div><?php endif; ?></td>
                            <td><?php echo $ativo ? '<span class="badge-app badge-success">Ativo</span>' : '<span class="badge-app badge-neutral">Inativo</span>'; ?></td>
                            <td class="text-muted small"><?php echo $u['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acesso'])) : 'Nunca acessou'; ?></td>
                            <td class="text-end" style="white-space: nowrap;">
                                <button type="button" class="btn btn-sm btn-light-app" title="Editar"
                                        data-bs-toggle="modal" data-bs-target="#modalEditar"
                                        data-id="<?php echo $u['id']; ?>" data-nome="<?php echo e($u['nome']); ?>" data-perfil="<?php echo $u['perfil']; ?>" data-filial="<?php echo (int)$u['filial_id']; ?>" data-eu="<?php echo $sou_eu ? 1 : 0; ?>">
                                    <i class="bi bi-pencil me-0"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-light-app" title="Redefinir senha"
                                        data-bs-toggle="modal" data-bs-target="#modalSenha"
                                        data-id="<?php echo $u['id']; ?>" data-login="<?php echo e($u['login']); ?>">
                                    <i class="bi bi-key me-0"></i>
                                </button>
                                <?php if (!$sou_eu): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $ativo ? 'Inativar' : 'Reativar'; ?> o usuário <?php echo e(addslashes($u['login'])); ?>?');">
                                    <input type="hidden" name="acao" value="status">
                                    <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="novo_status" value="<?php echo $ativo ? 0 : 1; ?>">
                                    <?php if ($ativo): ?>
                                        <button type="submit" class="btn btn-sm btn-light-app text-danger" title="Inativar"><i class="bi bi-person-dash me-0"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-light-app text-success" title="Reativar"><i class="bi bi-person-check me-0"></i></button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-custom overflow-hidden">
            <div class="p-3 px-4 border-bottom">
                <h5 class="m-0">Permissões por perfil</h5>
                <div class="text-muted small mt-1">Áreas do sistema que cada perfil pode acessar.</div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Perfil</th>
                            <?php foreach (itens_menu() as $grupo => $itens): ?>
                                <th class="text-center"><?php echo e($grupo); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($perfis as $chave => $rotulo): ?>
                        <tr>
                            <td><span class="badge-app <?php echo $cores_perfil[$chave]; ?>"><?php echo e($rotulo); ?></span></td>
                            <?php foreach (itens_menu() as $grupo => $itens): $tem = in_array($grupo, grupos_por_perfil()[$chave], true); ?>
                                <td class="text-center" title="<?php echo e(implode(', ', array_column($itens, 0))); ?>">
                                    <?php echo $tem ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle" style="color:#c3cbd6"></i>'; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="acao" value="editar">
            <input type="hidden" name="id" id="editar_id">
            <div class="modal-header">
                <h5 class="modal-title">Editar usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nome completo</label>
                    <input type="text" name="nome" id="editar_nome" class="form-control" required>
                </div>
                <div>
                    <label class="form-label">Perfil</label>
                    <select name="perfil" id="editar_perfil" class="form-select js-perfil" required>
                        <?php foreach ($perfis as $chave => $rotulo): ?>
                            <option value="<?php echo $chave; ?>"><?php echo e($rotulo); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-none" id="editar_aviso">Você não pode alterar o seu próprio perfil.</small>
                </div>
                <div class="mt-3 js-campo-filial" style="display:none">
                    <label class="form-label">Filial</label>
                    <select name="filial_id" id="editar_filial" class="form-select">
                        <option value="">Selecione...</option>
                        <?php foreach ($filiais as $fil): ?>
                            <option value="<?php echo (int)$fil['id']; ?>"><?php echo e($fil['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">A supervisão só vê as informações desta filial.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalSenha" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" autocomplete="off">
            <input type="hidden" name="acao" value="senha">
            <input type="hidden" name="id" id="senha_id">
            <div class="modal-header">
                <h5 class="modal-title">Redefinir senha · <span id="senha_login" class="text-muted fw-normal"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Nova senha</label>
                <input type="password" name="senha" class="form-control" minlength="8" autocomplete="new-password" required>
                <small class="text-muted">Mínimo de 8 caracteres. Informe a nova senha ao usuário por um canal seguro.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-key"></i>Redefinir</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filial só para o perfil de supervisão (obrigatória nele)
function mostrarFilial(selectPerfil) {
    const campo = selectPerfil.closest('form').querySelector('.js-campo-filial');
    const supervisao = selectPerfil.value === 'supervisao';
    campo.style.display = supervisao ? '' : 'none';
    campo.querySelector('select').required = supervisao;
}
document.querySelectorAll('.js-perfil').forEach(function (s) {
    s.addEventListener('change', function () { mostrarFilial(s); });
});

// Preenche os modais com os dados da linha clicada
document.getElementById('modalEditar').addEventListener('show.bs.modal', function (ev) {
    const b = ev.relatedTarget.dataset;
    document.getElementById('editar_id').value = b.id;
    document.getElementById('editar_nome').value = b.nome;
    const perfil = document.getElementById('editar_perfil');
    perfil.value = b.perfil;
    perfil.disabled = b.eu === '1';
    document.getElementById('editar_filial').value = b.filial !== '0' ? b.filial : '';
    mostrarFilial(perfil);
    document.getElementById('editar_aviso').classList.toggle('d-none', b.eu !== '1');
    // Campo desabilitado não é enviado; mantém o perfil atual num campo oculto
    let oculto = this.querySelector('input[name="perfil"]');
    if (b.eu === '1') {
        if (!oculto) { oculto = document.createElement('input'); oculto.type = 'hidden'; oculto.name = 'perfil'; this.querySelector('form').appendChild(oculto); }
        oculto.value = b.perfil;
    } else if (oculto) {
        oculto.remove();
    }
});
document.getElementById('modalSenha').addEventListener('show.bs.modal', function (ev) {
    document.getElementById('senha_id').value = ev.relatedTarget.dataset.id;
    document.getElementById('senha_login').textContent = ev.relatedTarget.dataset.login;
});
</script>

<?php render_rodape(); ?>
