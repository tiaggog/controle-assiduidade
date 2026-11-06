<?php
require_once 'conexao.php';

// Destino após o login: só aceita páginas internas do sistema (sem domínio nem pastas)
function destino_seguro($voltar, $perfil) {
    if ($voltar && preg_match('/^[a-z_]+\.php(\?[^\s]*)?$/', $voltar) && pode_acessar(strtok($voltar, '?'), $perfil)) {
        return $voltar;
    }
    return pagina_inicial($perfil);
}

$voltar = $_GET['voltar'] ?? '';
$erro = '';
$primeiro_acesso = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() === 0;

// Já logado: segue direto para o sistema
if (usuario_atual()) {
    header('Location: ' . destino_seguro($voltar, usuario_atual()['perfil']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');

    if ($primeiro_acesso) {
        // Criação do primeiro administrador
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $login)) {
            $erro = 'Informe o nome e um usuário com 3 a 50 caracteres (letras, números, ponto, hífen ou sublinhado).';
        } elseif (strlen($senha) < 8) {
            $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        } elseif ($senha !== ($_POST['senha_confirma'] ?? '')) {
            $erro = 'A confirmação de senha não confere.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, login, senha_hash, perfil, ultimo_acesso) VALUES (:nome, :login, :hash, 'admin', NOW())");
            $stmt->execute([':nome' => $nome, ':login' => $login, ':hash' => password_hash($senha, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int)$pdo->lastInsertId();
            header('Location: usuarios.php');
            exit;
        }
    } else {
        $stmt = $pdo->prepare("SELECT id, perfil, senha_hash FROM usuarios WHERE login = :login AND status = 1");
        $stmt->execute([':login' => $login]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int)$usuario['id'];
            $pdo->prepare("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id")->execute([':id' => $usuario['id']]);
            header('Location: ' . destino_seguro($voltar, $usuario['perfil']));
            exit;
        }
        // Atraso fixo para dificultar tentativas em sequência
        usleep(600000);
        $erro = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar · Grupo JB</title>
    <link rel="icon" type="image/png" href="logo_jb.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo filemtime(__DIR__ . '/assets/app.css'); ?>">
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <aside class="login-brand">
            <img src="logo_jb.png" alt="Grupo JB Transporte e Logística" class="login-logo">

            <div class="login-brand-body">
                <span class="login-eyebrow">Controle de Assiduidade</span>
                <h2>Gestão de terceirizados com controle e transparência.</h2>
                <p class="login-brand-lead">Frequência, banco de horas e faturamento reunidos em um único sistema.</p>

                <ul class="login-features">
                    <li>
                        <span class="ico"><i class="bi bi-calendar2-check"></i></span>
                        <div><strong>Frequência diária</strong><span>Horários, substituições e extras por empresa</span></div>
                    </li>
                    <li>
                        <span class="ico"><i class="bi bi-hourglass-split"></i></span>
                        <div><strong>Banco de horas</strong><span>Créditos, débitos e saldo por funcionário</span></div>
                    </li>
                    <li>
                        <span class="ico"><i class="bi bi-receipt"></i></span>
                        <div><strong>Pré-fatura e faturamento</strong><span>Fechamento do período com exportação</span></div>
                    </li>
                </ul>
            </div>

            <div class="login-brand-foot">
                <span>© <?php echo date('Y'); ?> Grupo JB Transporte e Logística</span>
                <span><i class="bi bi-shield-lock me-1"></i>Acesso restrito</span>
            </div>
        </aside>

        <main class="login-panel">
            <form method="POST" class="login-panel-body" autocomplete="on">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">

                <?php if ($primeiro_acesso): ?>
                    <span class="badge-app badge-brand">Primeiro acesso</span>
                    <h1>Criar administrador</h1>
                    <p class="login-sub">Nenhum usuário cadastrado ainda. Crie o administrador, que poderá cadastrar os demais usuários.</p>
                <?php else: ?>
                    <span class="login-eyebrow">Acesso ao sistema</span>
                    <h1>Bem-vindo de volta</h1>
                    <p class="login-sub">Informe seu usuário e senha para continuar.</p>
                <?php endif; ?>

                <?php if ($erro) echo alerta('danger', e($erro)); ?>

                <?php if ($primeiro_acesso): ?>
                <div class="mb-3">
                    <label class="form-label" for="nome">Nome completo</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                        <input type="text" id="nome" name="nome" class="form-control" value="<?php echo e($_POST['nome'] ?? ''); ?>" required>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="login">Usuário</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" id="login" name="login" class="form-control" placeholder="seu.usuario" value="<?php echo e($_POST['login'] ?? ''); ?>" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="senha">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="senha" name="senha" class="form-control" placeholder="••••••••" autocomplete="<?php echo $primeiro_acesso ? 'new-password' : 'current-password'; ?>" required <?php echo $primeiro_acesso ? 'minlength="8"' : ''; ?>>
                        <button type="button" class="btn-toggle-senha" data-alvo="senha" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                    </div>
                </div>

                <?php if ($primeiro_acesso): ?>
                <div class="mb-3">
                    <label class="form-label" for="senha_confirma">Confirmar senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="senha_confirma" name="senha_confirma" class="form-control" placeholder="••••••••" autocomplete="new-password" required minlength="8">
                        <button type="button" class="btn-toggle-senha" data-alvo="senha_confirma" aria-label="Mostrar senha"><i class="bi bi-eye"></i></button>
                    </div>
                    <small class="text-muted">Mínimo de 8 caracteres.</small>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary-custom w-100 login-submit">
                    <?php echo $primeiro_acesso ? '<i class="bi bi-shield-check"></i>Criar e entrar' : 'Entrar<i class="bi bi-arrow-right ms-2 me-0"></i>'; ?>
                </button>

                <?php if (!$primeiro_acesso): ?>
                <div class="login-help">
                    <i class="bi bi-info-circle"></i>
                    <span>Esqueceu a senha ou não tem acesso? Fale com o administrador do sistema para redefinir sua senha.</span>
                </div>
                <?php endif; ?>
            </form>

            <div class="login-panel-foot">
                <i class="bi bi-lock-fill"></i>Conexão protegida · sessão individual por usuário
            </div>
        </main>
    </div>
</div>

<script>
// Mostrar/ocultar a senha digitada
document.querySelectorAll('.btn-toggle-senha').forEach(function (botao) {
    botao.addEventListener('click', function () {
        const campo = document.getElementById(botao.dataset.alvo);
        const mostrar = campo.type === 'password';
        campo.type = mostrar ? 'text' : 'password';
        botao.querySelector('i').className = mostrar ? 'bi bi-eye-slash' : 'bi bi-eye';
        botao.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
        campo.focus();
    });
});
</script>
</body>
</html>
