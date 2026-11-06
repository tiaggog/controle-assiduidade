<?php
// Funcoes compartilhadas entre as telas do sistema

// Calcula as horas entre entrada e saida (HH:MM). Se a saida for menor, considera virada de dia.
function calcular_horas($entrada, $saida) {
    if (!$entrada || !$saida) {
        return null;
    }
    $ini = strtotime('1970-01-01 ' . $entrada . ' UTC');
    $fim = strtotime('1970-01-01 ' . $saida . ' UTC');
    if ($ini === false || $fim === false) {
        return null;
    }
    if ($fim < $ini) {
        $fim += 86400;
    }
    return round(($fim - $ini) / 3600, 2);
}

// Divide as horas do dia em normais (ate a carga) e extras (o que passar da carga)
function separar_horas($horas, $carga) {
    $horas = (float)$horas;
    $carga = (float)$carga;
    return [min($horas, $carga), max($horas - $carga, 0)];
}

function hora_curta($hora) {
    return $hora ? substr($hora, 0, 5) : '';
}

// Horas decimais em horas:minutos (7,67 -> 07:40)
function hhmm($horas) {
    $minutos = (int)round(abs((float)$horas) * 60);
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

// Exibição de horas na tela: horas:minutos, com sinal quando negativo
function fmt_horas($valor) {
    $valor = (float)$valor;
    return ((int)round($valor * 60) < 0 ? '−' : '') . hhmm($valor);
}

function fmt_moeda($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function e($texto) {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}

// Alerta padrao do sistema (sucesso, erro ou aviso)
function alerta($tipo, $texto) {
    $icones = ['success' => 'check-circle-fill', 'danger' => 'exclamation-octagon-fill', 'warning' => 'exclamation-triangle-fill'];
    // Confirmações (sucesso) somem sozinhas depois de alguns segundos; erros e avisos ficam até a pessoa ler
    return '<div class="alert alert-' . $tipo . ' mb-4' . ($tipo === 'success' ? ' alerta-some' : '') . '" role="alert"><i class="bi bi-' . ($icones[$tipo] ?? 'info-circle-fill') . '"></i><span>' . $texto . '</span></div>';
}

// Envia um CSV pronto para abrir no Excel (separador ; e BOM UTF-8) e encerra a requisicao
function exportar_csv($nome_arquivo, array $cabecalho, array $linhas) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, $cabecalho, ';');
    foreach ($linhas as $linha) {
        fputcsv($saida, $linha, ';');
    }
    fclose($saida);
    exit;
}

// Numero no formato brasileiro para o CSV (Excel pt-BR usa virgula decimal)
function csv_num($valor) {
    return number_format((float)$valor, 2, ',', '');
}

function lista_empresas(PDO $pdo) {
    return $pdo->query("SELECT id, razao_social FROM empresas_terceirizadas ORDER BY razao_social ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Estrutura do menu lateral: grupo => [arquivo => [rotulo, icone]]
function itens_menu() {
    return [
        'Cadastros' => [
            'cadastro_funcionario.php' => ['Funcionários', 'people'],
            'controle_documentos.php'  => ['Documentos', 'folder-check'],
            'cadastro_filial.php'      => ['Filiais', 'buildings'],
        ],
        'Operação' => [
            'lancar_frequencia.php'    => ['Lançar Frequência', 'calendar2-check'],
        ],
        'Relatórios' => [
            'controle_presenca.php' => ['Controle de Presença', 'grid-3x3-gap'],
            'banco_horas.php'       => ['Banco de Horas', 'hourglass-split'],
        ],
        'Financeiro' => [
            'pre_fatura.php'           => ['Pré-Fatura', 'receipt'],
            'calcular_faturamento.php' => ['Faturamento', 'cash-coin'],
            'tabela_valores.php'       => ['Tabela de Valores', 'table'],
        ],
        'Perfis' => [
            'usuarios.php' => ['Usuários', 'person-gear'],
        ],
    ];
}

function pagina_ativa($arquivo) {
    $pagina_atual = basename($_SERVER['PHP_SELF']);
    return $pagina_atual === $arquivo || ($arquivo === 'calcular_faturamento.php' && in_array($pagina_atual, ['relatorio_financeiro.php', 'fatura_salva.php'], true));
}

// ---------------------------------------------------------------------------
// Usuários e permissões
// ---------------------------------------------------------------------------

function perfis() {
    return [
        'admin'      => 'Administrador',
        'financeiro' => 'Financeiro',
        'supervisao' => 'Supervisão',
        'gestao'     => 'Gestão',
        'rh'         => 'Recursos Humanos',
    ];
}

// Grupos do menu que cada perfil pode acessar (é aqui que se altera uma permissão)
function grupos_por_perfil() {
    return [
        'admin'      => ['Cadastros', 'Operação', 'Relatórios', 'Financeiro', 'Perfis'],
        'financeiro' => ['Relatórios', 'Financeiro'],
        'supervisao' => ['Cadastros', 'Operação', 'Relatórios'],
        'gestao'     => ['Relatórios', 'Financeiro'],
        // RH: cadastros (funcionários e documentos); a configuração dos documentos continua só do admin
        'rh'         => ['Cadastros'],
    ];
}

function grupo_da_pagina($arquivo) {
    if (in_array($arquivo, ['relatorio_financeiro.php', 'fatura_salva.php'], true)) {
        $arquivo = 'calcular_faturamento.php';
    }
    foreach (itens_menu() as $grupo => $itens) {
        if (isset($itens[$arquivo])) {
            return $grupo;
        }
    }
    return null;
}

function pode_acessar($arquivo, $perfil = null) {
    $perfil = $perfil ?? (usuario_atual()['perfil'] ?? null);
    // Documentos: somente admin e RH
    if ($arquivo === 'controle_documentos.php') {
        return in_array($perfil, ['admin', 'rh'], true);
    }
    // Cadastro de filiais: somente o admin
    if ($arquivo === 'cadastro_filial.php') {
        return $perfil === 'admin';
    }
    $grupo = grupo_da_pagina($arquivo);
    if ($grupo === null) {
        return $perfil !== null;
    }
    return in_array($grupo, grupos_por_perfil()[$perfil] ?? [], true);
}

// Primeira página liberada para o perfil (destino após o login)
function pagina_inicial($perfil) {
    foreach (itens_menu() as $itens) {
        foreach (array_keys($itens) as $arquivo) {
            if (pode_acessar($arquivo, $perfil)) {
                return $arquivo;
            }
        }
    }
    return 'login.php';
}

function usuario_atual() {
    return $GLOBALS['usuario_logado'] ?? null;
}

function usuario_admin() {
    return (usuario_atual()['perfil'] ?? '') === 'admin';
}

// ---------------------------------------------------------------------------
// Filiais: a supervisão só enxerga a própria filial; admin, financeiro e gestão veem todas
// ---------------------------------------------------------------------------

function lista_filiais(PDO $pdo, $somente_ativas = true) {
    return $pdo->query("SELECT id, nome, status FROM filiais" . ($somente_ativas ? " WHERE status = 1" : "") . " ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function nome_filial(array $filiais, $filial_id) {
    foreach ($filiais as $f) {
        if ((int)$f['id'] === (int)$filial_id) {
            return $f['nome'];
        }
    }
    return '';
}

// Filial fixa do usuário logado: a da supervisão (-1 se ela ainda não tiver filial: não vê nada) ou null para quem vê todas
function filial_do_usuario() {
    $usuario = usuario_atual();
    if (($usuario['perfil'] ?? '') !== 'supervisao') {
        return null;
    }
    return (int)($usuario['filial_id'] ?? 0) ?: -1;
}

// Filial em uso na tela: a da supervisão ou a escolhida no filtro (0 = todas)
function filial_filtrada() {
    return filial_do_usuario() ?? (int)($_GET['filial_id'] ?? $_POST['filial_id'] ?? 0);
}

// Trecho SQL que restringe a uma filial (vazio = todas)
function sql_filial($coluna, $filial_id) {
    return $filial_id ? " AND $coluna = " . (int)$filial_id : '';
}

// O usuário logado pode ver/alterar este colaborador? (supervisão: só da filial dela)
function pode_ver_funcionario(PDO $pdo, $funcionario_id) {
    $filial = filial_do_usuario();
    if ($filial === null) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM funcionarios WHERE id = :id AND filial_id = :filial");
    $stmt->execute([':id' => (int)$funcionario_id, ':filial' => $filial]);
    return (int)$stmt->fetchColumn() > 0;
}

// Campo de filial nos filtros: fixo para a supervisão, seletor para os demais
function render_campo_filial(array $filiais, $filial_id, $nome = 'filial_id', $rotulo_todas = 'Todas as filiais', $classe = 'form-select', $atributos = '') {
    if (filial_do_usuario() !== null) {
        $nome_fixo = nome_filial($filiais, filial_do_usuario()) ?: 'Sem filial definida';
        echo '<input type="text" class="' . ($classe === 'form-select' ? 'form-control' : 'form-control form-control-sm') . '" value="' . e($nome_fixo) . '" disabled title="Sua filial">';
        return;
    }
    echo '<select name="' . e($nome) . '" class="' . e($classe) . '"' . $atributos . '>';
    if ($rotulo_todas !== null) {
        echo '<option value="">' . e($rotulo_todas) . '</option>';
    }
    foreach ($filiais as $f) {
        echo '<option value="' . (int)$f['id'] . '"' . ((int)$filial_id === (int)$f['id'] ? ' selected' : '') . '>' . e($f['nome']) . '</option>';
    }
    echo '</select>';
}

function iniciar_sessao() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_name('assiduidade_sessao');
        session_start();
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido() {
    return isset($_POST['_csrf']) && hash_equals(csrf_token(), (string)$_POST['_csrf']);
}

// Exige login, valida o token dos formulários e bloqueia páginas fora do perfil
function proteger_pagina(PDO $pdo) {
    iniciar_sessao();
    $pagina = basename($_SERVER['PHP_SELF']);

    if (!empty($_SESSION['usuario_id'])) {
        // Recarrega o usuário a cada acesso: inativação e troca de perfil valem na hora
        $stmt = $pdo->prepare("SELECT id, nome, login, perfil, filial_id FROM usuarios WHERE id = :id AND status = 1");
        $stmt->execute([':id' => $_SESSION['usuario_id']]);
        $GLOBALS['usuario_logado'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$GLOBALS['usuario_logado']) {
            $_SESSION = [];
            session_destroy();
            iniciar_sessao();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valido()) {
        http_response_code(400);
        die('Sessão expirada ou formulário inválido. Volte e tente novamente.');
    }

    if (in_array($pagina, ['login.php', 'logout.php'], true)) {
        return;
    }

    $usuario = usuario_atual();
    if (!$usuario) {
        header('Location: login.php?voltar=' . urlencode($pagina . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')));
        exit;
    }

    if (!pode_acessar($pagina, $usuario['perfil'])) {
        http_response_code(403);
        render_cabecalho('Acesso negado');
        ?>
        <div class="card card-custom p-5 text-center" style="max-width: 560px;">
            <i class="bi bi-shield-lock" style="font-size: 2.5rem; color: var(--app-danger);"></i>
            <h4 class="mt-3 mb-2">Você não tem permissão para acessar esta página</h4>
            <p class="text-muted mb-4">Seu perfil (<?php echo e(perfis()[$usuario['perfil']]); ?>) não inclui esta área do sistema. Fale com um administrador se precisar de acesso.</p>
            <div><a href="<?php echo e(pagina_inicial($usuario['perfil'])); ?>" class="btn btn-primary-custom"><i class="bi bi-house"></i>Ir para o início</a></div>
        </div>
        <?php
        render_rodape();
        exit;
    }
}

function render_menu() {
    ?>
<aside class="app-sidebar offcanvas-lg offcanvas-start no-print" tabindex="-1" id="menuLateral">
    <a class="app-brand" href="index.php">
        <img src="logo_jb.png" alt="Grupo JB Transporte e Logística" class="app-brand-logo">
        <span class="app-brand-sub">Controle de Assiduidade</span>
    </a>
    <nav class="app-nav">
        <?php
        $icones_grupo = ['Cadastros' => 'folder2-open', 'Operação' => 'clipboard-data', 'Relatórios' => 'bar-chart-line', 'Financeiro' => 'bank', 'Perfis' => 'shield-lock'];
        foreach (itens_menu() as $grupo => $itens):
            // Mostra só as páginas liberadas para o perfil; grupo vazio some do menu
            $itens = array_filter($itens, 'pode_acessar', ARRAY_FILTER_USE_KEY);
            if (!$itens) {
                continue;
            }
            // O grupo da página atual já abre expandido
            $aberto = count(array_filter(array_keys($itens), 'pagina_ativa')) > 0;
            $id_grupo = 'grupo_' . md5($grupo);
        ?>
            <button type="button" class="app-nav-group <?php echo $aberto ? 'is-current' : 'collapsed'; ?>" data-bs-toggle="collapse" data-bs-target="#<?php echo $id_grupo; ?>" aria-expanded="<?php echo $aberto ? 'true' : 'false'; ?>" aria-controls="<?php echo $id_grupo; ?>">
                <i class="bi bi-<?php echo $icones_grupo[$grupo] ?? 'folder'; ?>"></i>
                <span><?php echo e($grupo); ?></span>
                <i class="bi bi-chevron-down app-nav-chevron"></i>
            </button>
            <div class="collapse app-nav-sub <?php echo $aberto ? 'show' : ''; ?>" id="<?php echo $id_grupo; ?>">
                <?php foreach ($itens as $arquivo => [$rotulo, $icone]): ?>
                    <a href="<?php echo $arquivo; ?>" class="<?php echo pagina_ativa($arquivo) ? 'active' : ''; ?>">
                        <i class="bi bi-<?php echo $icone; ?>"></i><span><?php echo e($rotulo); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="app-sidebar-footer">© <?php echo date('Y'); ?> · Grupo JB Transporte e Logística</div>
</aside>
    <?php
}

function render_cabecalho($titulo, $subtitulo = '') {
    // Grupo do menu da pagina atual, usado no caminho da barra superior
    $grupo_atual = '';
    foreach (itens_menu() as $grupo => $itens) {
        foreach ($itens as $arquivo => $item) {
            if (pagina_ativa($arquivo)) {
                $grupo_atual = $grupo;
            }
        }
    }
    $dias = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($titulo); ?> · Grupo JB</title>
    <link rel="icon" type="image/png" href="logo_jb.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo filemtime(__DIR__ . '/assets/app.css'); ?>">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <script>
        // Aplica o menu recolhido antes de desenhar a página (evita o menu "piscar")
        try { if (localStorage.getItem('menu_recolhido') === '1') document.documentElement.classList.add('menu-recolhido'); } catch (e) {}
        // Modo noite escolhido pelo usuário (fica guardado neste navegador)
        try { if (localStorage.getItem('tema') === 'escuro') document.documentElement.setAttribute('data-tema', 'escuro'); } catch (e) {}
    </script>
</head>
<body>
<?php render_menu(); $usuario = usuario_atual(); ?>

<div class="app-main">
    <header class="app-topbar no-print">
        <button class="app-menu-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral" aria-label="Abrir menu">
            <i class="bi bi-list"></i>
        </button>
        <button class="app-menu-toggle d-none d-lg-inline-flex align-items-center justify-content-center" type="button" id="botaoRecolherMenu" onclick="alternarMenu()" aria-label="Recolher menu" title="Recolher menu">
            <i class="bi bi-chevron-double-left"></i>
        </button>
        <div class="app-breadcrumb">
            <?php if ($grupo_atual): ?><?php echo e($grupo_atual); ?><i class="bi bi-chevron-right"></i><?php endif; ?>
            <strong><?php echo e($titulo); ?></strong>
        </div>
        <div class="app-topbar-date d-none d-md-flex">
            <i class="bi bi-calendar3"></i>
            <?php echo $dias[(int)date('w')] . ', ' . date('d/m/Y'); ?>
        </div>
        <?php if ($usuario): ?>
        <div class="d-flex align-items-center gap-2 ms-auto ms-md-0">
        <button class="app-sino app-tema" type="button" onclick="alternarTema()" id="botaoTema" title="Mudar para o modo noite" aria-label="Mudar entre modo dia e modo noite">
            <i class="bi bi-moon-stars-fill"></i><i class="bi bi-sun-fill"></i>
        </button>
        <?php render_notificacoes(); ?>
        <div class="dropdown">
            <button class="app-user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="app-user-avatar"><?php echo e(mb_strtoupper(mb_substr($usuario['nome'], 0, 1))); ?></span>
                <span class="d-none d-sm-block text-start">
                    <span class="app-user-name"><?php echo e($usuario['nome']); ?></span>
                    <span class="app-user-role"><?php echo e(perfis()[$usuario['perfil']]); ?></span>
                </span>
                <i class="bi bi-chevron-down small text-muted"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm">
                <div class="px-3 py-2 small text-muted">Conectado como <strong><?php echo e($usuario['login']); ?></strong></div>
                <div class="dropdown-divider"></div>
                <form method="POST" action="logout.php" class="m-0">
                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sair</button>
                </form>
            </div>
        </div>
        </div>
        <?php endif; ?>
    </header>

    <main class="app-content">
        <div class="page-header">
            <h1><?php echo e($titulo); ?></h1>
            <?php if ($subtitulo): ?><p><?php echo e($subtitulo); ?></p><?php endif; ?>
        </div>
    <?php
}

function render_rodape() {
    ?>
    </main>
</div>
<script>
// Recolhe/expande o menu lateral no computador; a escolha fica salva no navegador
function atualizarBotaoMenu() {
    const recolhido = document.documentElement.classList.contains('menu-recolhido');
    const botao = document.getElementById('botaoRecolherMenu');
    if (!botao) return;
    botao.title = recolhido ? 'Expandir menu' : 'Recolher menu';
    botao.setAttribute('aria-label', botao.title);
    botao.querySelector('i').className = recolhido ? 'bi bi-chevron-double-right' : 'bi bi-chevron-double-left';
}
function alternarMenu() {
    const recolhido = document.documentElement.classList.toggle('menu-recolhido');
    try { localStorage.setItem('menu_recolhido', recolhido ? '1' : '0'); } catch (e) {}
    atualizarBotaoMenu();
}
atualizarBotaoMenu();

// Modo dia / noite: alterna e guarda a escolha neste navegador
function atualizarBotaoTema() {
    const botao = document.getElementById('botaoTema');
    if (!botao) return;
    const escuro = document.documentElement.getAttribute('data-tema') === 'escuro';
    botao.title = escuro ? 'Mudar para o modo dia' : 'Mudar para o modo noite';
}
function alternarTema() {
    const escuro = document.documentElement.getAttribute('data-tema') !== 'escuro';
    if (escuro) {
        document.documentElement.setAttribute('data-tema', 'escuro');
    } else {
        document.documentElement.removeAttribute('data-tema');
    }
    try { localStorage.setItem('tema', escuro ? 'escuro' : 'claro'); } catch (e) {}
    atualizarBotaoTema();
}
atualizarBotaoTema();

// Caixa de seleção com pesquisa (selects com a classe js-select-busca): o select original continua no formulário.
// Com "multiple", cada item tem caixinha de marcar e dá para escolher vários.
document.querySelectorAll('select.js-select-busca').forEach(function (select) {
    const multiplo = select.multiple;
    const placeholder = select.dataset.placeholder || 'Selecione...';
    const caixa = document.createElement('div');
    caixa.className = 'select-busca' + (multiplo ? ' select-busca-multiplo' : '');
    select.parentNode.insertBefore(caixa, select);
    caixa.appendChild(select);
    select.classList.add('select-busca-original');
    select.tabIndex = -1;

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'form-select select-busca-botao';
    caixa.appendChild(botao);

    const menu = document.createElement('div');
    menu.className = 'select-busca-menu';
    menu.hidden = true;
    menu.innerHTML = '<div class="select-busca-campo"><i class="bi bi-search"></i><input type="text" class="form-control form-control-sm" placeholder="Pesquisar..." autocomplete="off"></div>'
        + (multiplo ? '<div class="select-busca-acoes"><button type="button" class="js-todos">Marcar todos</button><button type="button" class="js-limpar">Limpar</button></div>' : '')
        + '<ul class="select-busca-lista"></ul>';
    caixa.appendChild(menu);
    const busca = menu.querySelector('input');
    const lista = menu.querySelector('ul');
    const normalizar = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const visiveis = () => Array.from(select.options).filter(op => !op.hidden && !op.disabled);
    let ativo = -1;

    function sincronizar() {
        const marcados = Array.from(select.options).filter(op => op.selected && op.value !== '');
        if (multiplo) {
            botao.textContent = !marcados.length ? placeholder : (marcados.length === 1 ? marcados[0].text.trim() : marcados.length + ' funcionários selecionados');
            botao.title = marcados.map(op => op.text.trim()).join(', ');
        } else {
            const op = select.options[select.selectedIndex];
            botao.textContent = op ? op.text.trim() : placeholder;
        }
        botao.classList.toggle('vazio', !marcados.length);
        botao.disabled = select.disabled;
    }
    function avisar() {
        select.dispatchEvent(new Event('change', { bubbles: true }));
        sincronizar();
    }
    function montar() {
        const termo = normalizar(busca.value);
        lista.innerHTML = '';
        visiveis().forEach(function (op) {
            if (termo && !normalizar(op.text).includes(termo)) return;
            const item = document.createElement('li');
            item.dataset.valor = op.value;
            if (multiplo) {
                item.innerHTML = '<i class="bi bi-' + (op.selected ? 'check-square-fill' : 'square') + '"></i><span></span>';
                item.querySelector('span').textContent = op.text.trim();
            } else {
                item.textContent = op.text.trim();
            }
            if (op.selected && op.value !== '') item.classList.add('selecionado');
            item.addEventListener('mousedown', function (ev) { ev.preventDefault(); escolher(op); });
            lista.appendChild(item);
        });
        if (!lista.children.length) lista.innerHTML = '<li class="vazio">Nenhum resultado</li>';
        ativo = -1;
    }
    function abrir() {
        if (select.disabled) return;
        busca.value = '';
        montar();
        menu.hidden = false;
        busca.focus();
    }
    function fechar() { menu.hidden = true; }
    function escolher(op) {
        if (multiplo) {
            op.selected = !op.selected; // marca/desmarca e deixa a lista aberta
            avisar();
            const pos = ativo;
            montar();
            if (pos >= 0) marcar(pos);
            busca.focus();
        } else {
            select.value = op.value;
            avisar();
            fechar();
            botao.focus();
        }
    }
    function marcar(i) {
        const itens = lista.querySelectorAll('li:not(.vazio)');
        if (!itens.length) return;
        ativo = (i + itens.length) % itens.length;
        itens.forEach((li, j) => li.classList.toggle('ativo', j === ativo));
        itens[ativo].scrollIntoView({ block: 'nearest' });
    }

    botao.addEventListener('click', () => (menu.hidden ? abrir() : fechar()));
    busca.addEventListener('input', montar);
    busca.addEventListener('keydown', function (ev) {
        const itens = lista.querySelectorAll('li:not(.vazio)');
        if (ev.key === 'ArrowDown') { ev.preventDefault(); marcar(ativo + 1); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); marcar(ativo - 1); }
        else if (ev.key === 'Enter') {
            ev.preventDefault();
            const li = itens[ativo] || itens[0];
            const op = li && Array.from(select.options).find(o => o.value === li.dataset.valor);
            if (op) escolher(op);
        }
        else if (ev.key === 'Escape') { fechar(); botao.focus(); }
    });
    if (multiplo) {
        // "Marcar todos" marca só os que aparecem na pesquisa
        menu.querySelector('.js-todos').addEventListener('click', function () {
            const termo = normalizar(busca.value);
            visiveis().forEach(op => { if (!termo || normalizar(op.text).includes(termo)) op.selected = true; });
            avisar(); montar(); busca.focus();
        });
        menu.querySelector('.js-limpar').addEventListener('click', function () {
            Array.from(select.options).forEach(op => { op.selected = false; });
            avisar(); montar(); busca.focus();
        });
    }
    document.addEventListener('click', ev => { if (!caixa.contains(ev.target)) fechar(); });
    // Outros filtros (empresa, filial) mudam as opções e habilitam/desabilitam o select
    document.addEventListener('change', sincronizar);
    sincronizar();
});

// Mensagens de confirmação desaparecem após 4 segundos
document.querySelectorAll('.alerta-some').forEach(function (alerta) {
    setTimeout(function () {
        alerta.style.transition = 'opacity .4s ease, margin .4s ease, max-height .4s ease, padding .4s ease';
        alerta.style.maxHeight = alerta.offsetHeight + 'px';
        alerta.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            alerta.style.opacity = '0';
            alerta.style.maxHeight = '0';
            alerta.style.marginBottom = '0';
            alerta.style.paddingTop = alerta.style.paddingBottom = '0';
        });
        setTimeout(function () { alerta.remove(); }, 450);
    }, 4000);
});

// Inclui o token de segurança em todos os formulários POST da página
document.querySelectorAll('form').forEach(function (form) {
    if ((form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    const campo = document.createElement('input');
    campo.type = 'hidden';
    campo.name = '_csrf';
    campo.value = document.querySelector('meta[name="csrf-token"]').content;
    form.appendChild(campo);
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

// Botoes de imprimir/PDF e exportar Excel usados nas tres saidas
function render_botoes_saida() {
    $query = $_GET;
    $query['formato'] = 'csv';
    ?>
    <div class="d-flex gap-2 no-print">
        <button type="button" class="btn btn-light-app btn-sm" onclick="window.print()"><i class="bi bi-printer"></i>Imprimir / PDF</button>
        <a class="btn btn-light-app btn-sm" href="?<?php echo e(http_build_query($query)); ?>"><i class="bi bi-file-earmark-spreadsheet text-success"></i>Exportar Excel</a>
    </div>
    <?php
}

// Funcionarios do quadro (todas as empresas) para o filtro dos relatorios
function lista_funcionarios_filtro(PDO $pdo) {
    // Inclui quem já teve frequência lançada (mesmo que hoje seja diarista); empresa = a do cadastro ou a do último lançamento
    return $pdo->query("SELECT f.id, f.nome, COALESCE(f.empresa_id, (SELECT c.empresa_id FROM controle_assiduidade c WHERE c.funcionario_id = f.id ORDER BY c.data_referencia DESC LIMIT 1)) AS empresa_id,
                               f.filial_id, f.status
                        FROM funcionarios f
                        WHERE (f.tipo = 'Funcionario' OR EXISTS (SELECT 1 FROM controle_assiduidade c WHERE c.funcionario_id = f.id))" . sql_filial('f.filial_id', filial_do_usuario()) . "
                        ORDER BY f.nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Funcionários escolhidos no filtro (lista vazia = todos); aceita um ou vários (funcionario_id[]=1&funcionario_id[]=2)
function funcionarios_filtrados() {
    return array_values(array_unique(array_filter(array_map('intval', (array)($_GET['funcionario_id'] ?? [])))));
}

// Normaliza um filtro de funcionário (id, lista de ids ou 0) para uma lista de ids
function ids_funcionarios($filtro) {
    return array_values(array_filter(array_map('intval', (array)$filtro)));
}

// Trecho SQL que restringe aos funcionários escolhidos (vazio = todos)
function sql_funcionarios($coluna, $filtro) {
    $ids = ids_funcionarios($filtro);
    return $ids ? " AND $coluna IN (" . implode(',', $ids) . ")" : '';
}

// Nomes dos funcionários escolhidos, para os cabeçalhos ("Fulano, Ciclano" ou "5 funcionários")
function nomes_funcionarios(array $lista, $filtro, $limite = 3) {
    $ids = array_flip(ids_funcionarios($filtro));
    $nomes = [];
    foreach ($lista as $f) {
        if (isset($ids[(int)$f['id']])) {
            $nomes[] = trim($f['nome']);
        }
    }
    if (!$nomes) {
        return '';
    }
    return count($nomes) <= $limite ? implode(', ', $nomes) : count($nomes) . ' funcionários';
}

// Filtro padrao (periodo + empresa) das saidas; com $funcionarios, mostra tambem o filtro por funcionario
function render_filtro_periodo(array $empresas, $data_inicio, $data_fim, $empresa_id, array $campos_extras = [], ?array $funcionarios = null) {
    $funcionarios_sel = array_flip(funcionarios_filtrados());
    // Com campos extras e filtro de funcionário, o filtro ocupa duas linhas completas e alinhadas:
    // [Data inicial | Data final | Filial | Empresa] e [Funcionário | campos extras | botão]
    $duas_linhas = $campos_extras && $funcionarios !== null;
    $col_data = $duas_linhas ? 'col-sm-6 col-md-4 col-xl-2' : 'col-sm-6 col-md-3 col-xl-2';
    $col_empresa = $duas_linhas ? 'col-md-12 col-xl-6' : 'col-md';
    $col_funcionario = $duas_linhas ? 'col-md-6' : 'col-md';
    $col_extra = $duas_linhas ? 'col-sm-6 col-md-3' : 'col-sm-6 col-md-3 col-xl-2';
    $col_botao = $duas_linhas ? 'col-sm-6 col-md-3' : 'col-md-auto';
    ?>
    <div class="card card-custom p-3 px-4 mb-4 no-print">
        <form method="GET" class="row g-3 align-items-end js-filtro-periodo">
            <div class="<?php echo $col_data; ?>">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_inicio" class="form-control" value="<?php echo e($data_inicio); ?>" required>
            </div>
            <div class="<?php echo $col_data; ?>">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?php echo e($data_fim); ?>" required>
            </div>
            <div class="<?php echo $col_data; ?>">
                <label class="form-label">Filial</label>
                <?php render_campo_filial(lista_filiais($GLOBALS['pdo']), filial_filtrada(), 'filial_id', 'Todas as filiais', 'form-select js-filtro-filial'); ?>
            </div>
            <div class="<?php echo $col_empresa; ?>">
                <label class="form-label">Empresa</label>
                <select name="empresa_id" class="form-select js-filtro-empresa" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo $empresa_id == $emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['razao_social']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($funcionarios !== null): ?>
            <div class="<?php echo $col_funcionario; ?>">
                <label class="form-label">Funcionário</label>
                <select name="funcionario_id[]" class="form-select js-filtro-funcionario js-select-busca" multiple data-placeholder="Todos os funcionários">
                    <?php foreach ($funcionarios as $func): ?>
                        <option value="<?php echo $func['id']; ?>" data-empresa="<?php echo (int)$func['empresa_id']; ?>" data-filial="<?php echo (int)$func['filial_id']; ?>" <?php echo isset($funcionarios_sel[(int)$func['id']]) ? 'selected' : ''; ?>>
                            <?php echo e($func['nome']) . ((int)$func['status'] === 1 ? '' : ' (inativo)'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <script>
            // Lista no filtro só os funcionários da empresa (e da filial) escolhida
            (function () {
                const form = document.currentScript.closest('form');
                const empresa = form.querySelector('.js-filtro-empresa');
                const filial = form.querySelector('.js-filtro-filial');
                const funcionario = form.querySelector('.js-filtro-funcionario');
                function filtrar() {
                    funcionario.querySelectorAll('option[data-empresa]').forEach(function (op) {
                        const visivel = op.dataset.empresa === empresa.value && (!filial || !filial.value || op.dataset.filial === filial.value);
                        op.hidden = !visivel;
                        op.disabled = !visivel;
                        if (!visivel) op.selected = false;
                    });
                    funcionario.disabled = !empresa.value;
                }
                empresa.addEventListener('change', filtrar);
                if (filial) filial.addEventListener('change', filtrar);
                filtrar();
            })();
            </script>
            <?php endif; ?>
            <?php foreach ($campos_extras as $nome => $rotulo): ?>
            <?php if (is_array($rotulo)): // campo numérico simples (ex.: dias úteis), com valor sugerido ?>
            <div class="<?php echo $col_extra; ?>">
                <label class="form-label"><?php echo e($rotulo['rotulo']); ?><?php if (!empty($rotulo['ajuda'])): ?> <i class="bi bi-info-circle ms-1" title="<?php echo e($rotulo['ajuda']); ?>" style="cursor: help;"></i><?php endif; ?></label>
                <div class="input-group">
                    <input type="number" step="1" min="1" max="31" name="<?php echo e($nome); ?>" class="form-control" value="<?php echo e($_GET[$nome] ?? $rotulo['valor'] ?? ''); ?>" required>
                    <span class="input-group-text"><?php echo e($rotulo['sufixo'] ?? ''); ?></span>
                </div>
            </div>
            <?php continue; endif; ?>
            <div class="<?php echo $col_extra; ?>">
                <label class="form-label"><?php echo e($rotulo); ?></label>
                <div class="input-group">
                    <span class="input-group-text">R$</span>
                    <input type="number" step="0.01" min="0" name="<?php echo e($nome); ?>" class="form-control" value="<?php echo e($_GET[$nome] ?? ''); ?>" placeholder="0,00" required>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="<?php echo $col_botao; ?>">
                <button type="submit" class="btn btn-primary-custom w-100 px-4"><i class="bi bi-funnel"></i>Gerar relatório</button>
            </div>
        </form>
    </div>
    <?php
}

// Cabecalho dos cartoes de relatorio (titulo, empresa, periodo e botoes de saida)
function render_cabecalho_relatorio($titulo, $empresa, $data_inicio, $data_fim, $complemento = '') {
    ?>
    <div class="p-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h4 class="m-0"><?php echo e($titulo); ?> <span class="text-muted fw-normal">· <?php echo e($empresa); ?></span></h4>
            <div class="text-muted small mt-1">
                <i class="bi bi-calendar-range me-1"></i><?php echo date('d/m/Y', strtotime($data_inicio)); ?> a <?php echo date('d/m/Y', strtotime($data_fim)); ?>
                <?php if ($complemento): ?><span class="mx-2">|</span><?php echo $complemento; ?><?php endif; ?>
            </div>
        </div>
        <?php render_botoes_saida(); ?>
    </div>
    <?php
}

function nome_empresa(array $empresas, $empresa_id) {
    foreach ($empresas as $emp) {
        if ($emp['id'] == $empresa_id) {
            return $emp['razao_social'];
        }
    }
    return '';
}

// Carga usada para separar horas normais e extras de quem vem como extra avulso (sem cadastro)
const CARGA_PADRAO_EXTRA = 8.0;

// Busca os lancamentos do dia que nao sao do quadro: substituicoes (diarista cadastrada)
// e extras (pessoa avulsa, nome digitado no lancamento)
// Com filial: só os lançamentos feitos na folha daquela filial
function buscar_lancamentos_diaristas(PDO $pdo, $empresa_id, $data_inicio, $data_fim, $filial_id = 0) {
    $sql = "SELECT l.*, COALESCE(d.nome, l.nome_avulso) AS diarista,
                   COALESCE(d.carga_horaria, " . CARGA_PADRAO_EXTRA . ") AS carga_diarista,
                   s.nome AS substituido, s.carga_horaria AS carga_substituido
            FROM lancamentos_diaristas l
            LEFT JOIN funcionarios d ON d.id = l.diarista_id
            LEFT JOIN funcionarios s ON s.id = l.substituido_id
            WHERE l.empresa_id = :empresa_id AND l.data_referencia BETWEEN :ini AND :fim" . sql_filial('l.filial_id', $filial_id) . "
            ORDER BY l.data_referencia ASC, d.nome ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id, ':ini' => $data_inicio, ':fim' => $data_fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Faturamento por dias trabalhados
// ---------------------------------------------------------------------------

function cargos() {
    return ['Lider' => 'Líder', 'Conferente' => 'Conferente', 'Ajudante' => 'Ajudante'];
}

function turnos() {
    return ['T1' => 'T1 · Diurno', 'T3' => 'T3 · Noturno'];
}

// Colunas da tabela de valores: categoria => [grupo, rótulo]
function categorias_valor() {
    return [
        'Lider'         => ['Fixo', 'Líder'],
        'Conferente'    => ['Fixo', 'Conferente'],
        'Ajudante'      => ['Fixo', 'Ajudante/Substituto'],
        'extra_semana'  => ['Diarista Extra', 'Semana'],
        'extra_domingo' => ['Diarista Extra', 'Domingos/Feriados'],
    ];
}

// Valores mensais cadastrados: [filial_id][categoria][turno] => valor
function tabela_valores(PDO $pdo) {
    $tabela = [];
    foreach ($pdo->query("SELECT filial_id, categoria, turno, valor FROM valores_categoria") as $v) {
        $tabela[(int)$v['filial_id']][$v['categoria']][$v['turno']] = (float)$v['valor'];
    }
    return $tabela;
}

// Domingo, ou dia em que alguém da filial foi lançado com a condição Domingo/Feriado (ou feriado antigo)
function dia_especial(PDO $pdo, $filial_id, $data) {
    if ((int)date('w', strtotime($data)) === 0) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM feriados WHERE filial_id = :filial_id AND data = :data
                           UNION SELECT 1 FROM controle_assiduidade WHERE filial_id = :filial_id2 AND data_referencia = :data2 AND dia_especial = 1 LIMIT 1");
    $stmt->execute([':filial_id' => (int)$filial_id, ':data' => $data, ':filial_id2' => (int)$filial_id, ':data2' => $data]);
    return (bool)$stmt->fetchColumn();
}

// Sugestão de dias úteis do período: segunda a sábado
function sugerir_dias_uteis($data_inicio, $data_fim) {
    $total = 0;
    for ($d = strtotime($data_inicio); $d <= strtotime($data_fim); $d = strtotime('+1 day', $d)) {
        $total += (int)date('w', $d) !== 0 ? 1 : 0;
    }
    return $total;
}

// Calcula a pré-fatura: valor mensal da tabela × dias trabalhados ÷ dias úteis.
// Domingo/feriado é cobrado pela coluna Diarista Extra › Domingos/Feriados. Horas extras não entram na pré-fatura.
// Retorna [linhas do quadro, substituições/extras, totais, avisos]
function calcular_pre_fatura(PDO $pdo, $empresa_id, $data_inicio, $data_fim, $funcionario_id, $filial_id, $dias_uteis) {
    $tabela = tabela_valores($pdo);
    $filiais = array_column(lista_filiais($pdo, false), 'nome', 'id');
    $func = $diar = $avisos = [];
    $totais = ['func' => 0.0, 'sub' => 0.0, 'extra' => 0.0, 'dias' => 0, 'he' => 0.0];
    $sem_valor = [];
    $valor_dia = function ($filial, $categoria, $turno) use ($tabela, $dias_uteis, &$sem_valor, $filiais) {
        $valor = $tabela[(int)$filial][$categoria][$turno] ?? 0.0;
        if ($valor <= 0) {
            $sem_valor[($filiais[$filial] ?? 'Filial') . ' · ' . (categorias_valor()[$categoria][0] === 'Fixo' ? '' : categorias_valor()[$categoria][0] . ' › ') . categorias_valor()[$categoria][1] . ' ' . $turno] = true;
        }
        return $dias_uteis > 0 ? $valor / $dias_uteis : 0.0;
    };
    $sql_especial = "(DAYOFWEEK(c.data_referencia) = 1 OR c.dia_especial = 1 OR EXISTS (SELECT 1 FROM feriados fe WHERE fe.filial_id = c.filial_id AND fe.data = c.data_referencia))";

    // Quadro fixo: valor final = valor mensal − faltas × valor p/ dia útil (falta descontada uma vez).
    // Falta coberta por diarista (Substituído) não é descontada: o posto foi coberto e já está no valor mensal.
    // Presente e Justificado contam como trabalhado; domingo/feriado trabalhado é somado pela coluna Domingos/Feriados.
    $sql = "SELECT f.id, f.nome, c.filial_id, COALESCE(c.cargo, f.cargo) AS cargo, COALESCE(c.turno, f.turno) AS turno,
                   SUM(NOT $sql_especial) AS dias_lancados,
                   SUM(NOT $sql_especial AND c.presenca = 'Falta') AS faltas,
                   SUM(NOT $sql_especial AND c.presenca = 'Falta' AND EXISTS (SELECT 1 FROM lancamentos_diaristas ld WHERE ld.substituido_id = c.funcionario_id
                       AND ld.data_referencia = c.data_referencia AND ld.empresa_id = c.empresa_id)) AS substituidos,
                   SUM($sql_especial AND c.presenca IN ('Presente', 'Justificado')) AS dias_esp,
                   SUM(CASE WHEN c.presenca = 'Presente' THEN GREATEST(c.horas_trabalhadas - f.carga_horaria, 0) ELSE 0 END) AS he
            FROM funcionarios f
            INNER JOIN controle_assiduidade c ON f.id = c.funcionario_id
            WHERE c.empresa_id = :empresa_id" . sql_filial('c.filial_id', $filial_id) . "
              AND c.data_referencia BETWEEN :ini AND :fim
              " . sql_funcionarios('f.id', $funcionario_id) . "
            GROUP BY f.id, f.nome, c.filial_id, COALESCE(c.cargo, f.cargo), COALESCE(c.turno, f.turno)
            ORDER BY f.nome ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id, ':ini' => $data_inicio, ':fim' => $data_fim]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vd = $vde = 0.0;
        if (!$r['cargo'] || !$r['turno']) {
            $avisos[] = e($r['nome']) . ' está sem ' . (!$r['cargo'] ? 'cargo' : 'turno') . ' no cadastro: ficou sem valor.';
        } else {
            $vd = (int)$r['dias_lancados'] ? $valor_dia($r['filial_id'], $r['cargo'], $r['turno']) : 0.0;
            $vde = (int)$r['dias_esp'] ? $valor_dia($r['filial_id'], 'extra_domingo', $r['turno']) : 0.0;
        }
        $dias_base = (int)$dias_uteis;
        $faltas = (int)$r['faltas'] - (int)$r['substituidos']; // só as faltas sem cobertura descontam
        $linha = [
            'nome' => $r['nome'], 'cargo' => $r['cargo'], 'turno' => $r['turno'], 'filial' => $filiais[$r['filial_id']] ?? '',
            'mensal' => round($vd * $dias_uteis, 2), 'vd' => $vd, 'dias_base' => $dias_base,
            'base' => round($vd * $dias_base, 2), 'faltas' => $faltas, 'substituidos' => (int)$r['substituidos'], 'desconto' => round($vd * $faltas, 2),
            'dias_esp' => (int)$r['dias_esp'], 'vde' => $vde, 'adicional' => round($vde * (int)$r['dias_esp'], 2),
            'he' => (float)$r['he'],
            // Dias trabalhados em dia normal (domingo/feriado ficam na coluna própria)
            'trabalhados' => max((int)$r['dias_lancados'] - (int)$r['faltas'], 0),
        ];
        $linha['bruto'] = round($vd * $linha['trabalhados'], 2);
        $linha['dias'] = $linha['trabalhados'];
        // Falta descontada uma vez só, do valor mensal (modelo da planilha)
        $linha['valor'] = round(max($linha['mensal'] - $linha['desconto'], 0) + $linha['adicional'], 2);
        $totais['func'] += $linha['valor'];
        $totais['dias'] += $linha['dias'] + $linha['dias_esp'];
        $totais['he'] += $linha['he'];
        $func[] = $linha;
    }
    // Diaristas: substituição usa a coluna do cargo da diarista (inclusive domingo/feriado); extra usa Diarista Extra › Semana ou Domingos/Feriados
    $stmt_turno = $pdo->prepare("SELECT cargo, turno FROM funcionarios WHERE id = :id");
    $especiais = [];
    foreach (buscar_lancamentos_diaristas($pdo, $empresa_id, $data_inicio, $data_fim, $filial_id) as $l) {
        if (ids_funcionarios($funcionario_id) && !in_array((int)$l['substituido_id'], ids_funcionarios($funcionario_id), true)) {
            continue;
        }
        // Cargo e turno gravados no lançamento; se não houver, os do cadastro atual
        $turno = $l['turno'];
        $cargo = $l['cargo'] ?? null;
        if ((!$turno || !$cargo) && $l['diarista_id']) {
            $stmt_turno->execute([':id' => $l['diarista_id']]);
            $cad = $stmt_turno->fetch(PDO::FETCH_ASSOC) ?: [];
            $turno = $turno ?: ($cad['turno'] ?? null);
            $cargo = $cargo ?: ($cad['cargo'] ?? null);
        }
        $chave_dia = $l['filial_id'] . '|' . $l['data_referencia'];
        $especial = $especiais[$chave_dia] ??= dia_especial($pdo, $l['filial_id'], $l['data_referencia']);
        $sub = (bool)$l['substituido_id'];
        $vd = 0.0;
        // Extra antigo (nome digitado, sem cadastro) usa o turno gravado no lançamento; extra não precisa de cargo
        if (!$l['diarista_id'] && !$turno) {
            $avisos[] = e($l['diarista']) . ' (' . date('d/m', strtotime($l['data_referencia'])) . ') é um extra antigo sem cadastro (nome digitado) e sem turno: o dia ficou sem valor.';
        } elseif (!$turno || ($sub && !$cargo)) {
            $avisos[] = e($l['diarista']) . ' (' . date('d/m', strtotime($l['data_referencia'])) . ') está sem ' . (!$turno ? 'turno' : 'cargo') . ' no cadastro: o dia ficou sem valor.';
        } else {
            $vd = $valor_dia($l['filial_id'], $sub ? $cargo : ($especial ? 'extra_domingo' : 'extra_semana'), $turno);
        }
        [, $he] = separar_horas($l['horas_trabalhadas'], $l['carga_diarista']);
        $linha = [
            'data' => $l['data_referencia'], 'nome' => $l['diarista'], 'substituiu' => $l['substituido'] ?? null,
            'tipo' => $sub ? 'Substituição' : 'Extra', 'cargo' => $cargo, 'turno' => $turno, 'filial' => $filiais[$l['filial_id']] ?? '',
            'especial' => $especial, 'vd' => $vd, 'he' => (float)$he, 'valor' => round($vd, 2),
        ];
        $totais[$sub ? 'sub' : 'extra'] += $linha['valor'];
        $totais['dias']++;
        $totais['he'] += $linha['he'];
        $diar[] = $linha;
    }

    if ($sem_valor) {
        $avisos[] = 'Sem valor na <a href="tabela_valores.php">Tabela de Valores</a>: ' . e(implode(', ', array_keys($sem_valor))) . '.';
    }
    // Substituição não soma no total (o dia já está no valor mensal do funcionário substituído); extra soma
    $totais['geral'] = $totais['func'] + $totais['extra'];
    return [$func, $diar, $totais, array_values(array_unique($avisos))];
}

// Tabelas da pré-fatura e da fatura salva (mesmo layout): quadro, substituições, extras e total
function render_tabelas_fatura(array $func, array $diar, array $totais, $escopo, $data_inicio, $data_fim, $complemento, $rotulo_total) {
    $cargos = cargos();
    ?>
    <?php
    $soma = fn($campo) => array_sum(array_column($func, $campo));
    $tem_esp = $soma('dias_esp') > 0;
    $cols = $tem_esp ? 9 : 8;
    ?>
    <div class="card card-custom overflow-hidden mb-4">
        <?php render_cabecalho_relatorio('Quadro de funcionários', $escopo, $data_inicio, $data_fim, $complemento); ?>
        <div class="table-responsive">
            <table class="table table-hover tabela-quadro-fatura">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Operação</th>
                        <th class="num">Valor mensal</th>
                        <th class="num">Valor p/ dia útil</th>
                        <th class="num">Dias trabalhados</th>
                        <th class="num">Faltas</th>
                        <th class="num th-desconto">Desconto de faltas</th>
                        <?php if ($tem_esp): ?><th class="num" title="Domingos e feriados trabalhados, pela coluna Domingos/Feriados">Dom/Feriado</th><?php endif; ?>
                        <th class="num">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($func): foreach ($func as $r): ?>
                    <tr>
                        <td class="fw-medium"><?php echo e($r['nome']); ?></td>
                        <td class="text-nowrap"><?php echo $r['cargo'] ? e(mb_strtoupper(cargos()[$r['cargo']] ?? $r['cargo'])) : '<span class="badge-app badge-warning">Sem cargo</span>'; ?> - <?php echo $r['turno'] ? ($r['turno'] === 'T3' ? 'NOTURNO' : 'DIURNO') : '<span class="badge-app badge-warning">Sem turno</span>'; ?></td>
                        <td class="num"><?php echo fmt_moeda($r['mensal']); ?></td>
                        <td class="num"><?php echo fmt_moeda($r['vd']); ?></td>
                        <td class="num"><?php echo (int)($r['trabalhados'] ?? $r['dias'] ?? 0); ?></td>
                        <td class="num<?php echo $r['faltas'] ? ' text-danger fw-semibold' : ''; ?>"><?php echo (int)$r['faltas']; ?></td>
                        <td class="num<?php echo $r['desconto'] > 0 ? ' text-danger' : ' text-muted'; ?>"><?php echo fmt_moeda($r['desconto']); ?></td>
                        <?php if ($tem_esp): ?><td class="num"><?php echo $r['dias_esp'] ? (int)$r['dias_esp'] . ' × ' . fmt_moeda($r['vde']) . '<div class="small text-muted">+ ' . fmt_moeda($r['adicional']) . '</div>' : '<span class="text-muted">—</span>'; ?></td><?php endif; ?>
                        <td class="num fw-semibold"><?php echo fmt_moeda($r['valor']); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="<?php echo $cols; ?>" class="table-empty"><i class="bi bi-inbox"></i>Nenhum lançamento do quadro no período.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($func): ?>
                <tfoot>
                    <tr>
                        <td colspan="2">Total do quadro</td>
                        <td class="num"><?php echo fmt_moeda($soma('mensal')); ?></td>
                        <td></td>
                        <td class="num"><?php echo (int)$soma('trabalhados'); ?></td>
                        <td class="num"><?php echo (int)$soma('faltas'); ?></td>
                        <td class="num text-danger"><?php echo fmt_moeda($soma('desconto')); ?></td>
                        <?php if ($tem_esp): ?><td class="num"><?php echo fmt_moeda($soma('adicional')); ?></td><?php endif; ?>
                        <td class="num"><?php echo fmt_moeda($totais['func']); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <style>
        .tabela-quadro-fatura th.th-desconto { color: var(--app-danger, #d92d20); }
    </style>
    <?php
    // Faturas salvas antes desta regra somavam as substituições no total: continuam mostrando o valor
    $sub_soma = abs($totais['geral'] - ($totais['func'] + $totais['extra'])) > 0.005;
    $secoes = [
        'sub'   => ['Substituições', 'arrow-left-right', $sub_soma
            ? 'Diaristas cobrindo faltas do quadro. Valor da coluna do cargo da diarista (Líder, Conferente ou Ajudante/Substituto), inclusive em domingo ou feriado.'
            : 'Diaristas cobrindo faltas do quadro. Não somam no total: o dia já está no valor mensal do funcionário substituído (a falta coberta não é descontada).', 'Nenhuma substituição no período.', 'Subtotal das substituições'],
        'extra' => ['Extras', 'person-plus', 'Diaristas chamadas por aumento de demanda. Valor da coluna Diarista Extra › Semana ou Domingos/Feriados.', 'Nenhum extra no período.', 'Subtotal dos extras'],
    ];
    foreach ($secoes as $chave => [$titulo, $icone, $descricao, $vazio, $rotulo]):
        $linhas = array_filter($diar, fn($l) => ($l['tipo'] === 'Extra') === ($chave === 'extra'));
        $com_valor = $chave === 'extra' || $sub_soma;
        $cols = ($chave === 'sub' ? 6 : 5) - ($com_valor ? 0 : 1);
    ?>
    <div class="card card-custom overflow-hidden mb-4">
        <div class="p-3 px-4 border-bottom">
            <h4 class="m-0"><i class="bi bi-<?php echo $icone; ?> me-2"></i><?php echo $titulo; ?> <span class="text-muted fw-normal small">· <?php echo count($linhas); ?></span></h4>
            <div class="text-muted small mt-1"><?php echo e($descricao); ?></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Diarista</th>
                        <?php if ($chave === 'sub'): ?><th>Substituiu</th><?php endif; ?>
                        <th>Cargo · turno</th>
                        <th>Dia</th>
                        <?php if ($com_valor): ?><th class="num">Valor do dia</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($linhas): foreach ($linhas as $l): ?>
                    <tr>
                        <td class="text-muted"><?php echo date('d/m/Y', strtotime($l['data'])); ?></td>
                        <td class="fw-medium"><?php echo e($l['nome']); ?></td>
                        <?php if ($chave === 'sub'): ?><td><?php echo e($l['substituiu'] ?? '—'); ?></td><?php endif; ?>
                        <td class="text-nowrap"><?php echo !empty($l['cargo']) ? e(cargos()[$l['cargo']] ?? $l['cargo']) . ' · ' : ''; ?><?php echo $l['turno'] ? e($l['turno']) : '<span class="badge-app badge-warning">Sem turno</span>'; ?></td>
                        <td><?php echo !$l['especial'] ? '<span class="text-muted">Semana</span>' : ($chave === 'extra' ? '<span class="badge-app badge-danger">Dom/Feriado</span>' : '<span class="badge-app badge-neutral" title="Substituição é cobrada pelo valor normal, mesmo em domingo ou feriado">Dom/Feriado</span>'); ?></td>
                        <?php if ($com_valor): ?><td class="num fw-semibold"><?php echo fmt_moeda($l['valor']); ?></td><?php endif; ?>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="<?php echo $cols; ?>" class="table-empty"><i class="bi bi-inbox"></i><?php echo $vazio; ?></td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($com_valor): ?><tfoot><tr><td colspan="<?php echo $cols - 1; ?>"><?php echo $rotulo; ?></td><td class="num"><?php echo fmt_moeda($totais[$chave]); ?></td></tr></tfoot><?php endif; ?>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="card card-custom p-3 px-4 d-flex flex-row flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold"><?php echo e($rotulo_total); ?> <span class="text-muted fw-normal small">(<?php echo $sub_soma ? 'quadro + substituições + extras' : 'quadro + extras'; ?>)</span></span>
        <span class="fw-bold fs-5" style="color: var(--app-primary)"><?php echo fmt_moeda($totais['geral']); ?></span>
    </div>
    <?php
}

// CSV da pré-fatura / fatura salva
function exportar_csv_fatura($nome_arquivo, array $func, array $diar, array $totais) {
    $linhas_quadro = [];
    foreach ($func as $r) {
        $operacao = ($r['cargo'] ? mb_strtoupper(cargos()[$r['cargo']] ?? $r['cargo']) : '') . ' - ' . ($r['turno'] === 'T3' ? 'NOTURNO' : ($r['turno'] ? 'DIURNO' : ''));
        $linhas_quadro[] = [$r['nome'], $operacao, csv_num($r['mensal']), csv_num($r['vd']), $r['trabalhados'], $r['faltas'], csv_num($r['desconto']), $r['dias_esp'], csv_num($r['adicional']), csv_num($r['valor'])];
    }
    $linhas_quadro[] = ['TOTAL DO QUADRO', '', csv_num(array_sum(array_column($func, 'mensal'))), '', array_sum(array_column($func, 'trabalhados')), array_sum(array_column($func, 'faltas')),
                        csv_num(array_sum(array_column($func, 'desconto'))), array_sum(array_column($func, 'dias_esp')), csv_num(array_sum(array_column($func, 'adicional'))),
                        csv_num($totais['func'])];
    $linhas_quadro[] = [];
    $sub_soma = abs($totais['geral'] - ($totais['func'] + $totais['extra'])) > 0.005;
    $linhas_quadro[] = ['Tipo', 'Data', 'Diarista', 'Substituiu', 'Turno', 'Dia', 'Valor do dia'];
    foreach ($diar as $l) {
        // Substituição em domingo/feriado continua no valor normal (Ajudante/Substituto)
        $esp = $l['especial'] && $l['tipo'] === 'Extra';
        $linhas_quadro[] = [$l['tipo'], date('d/m/Y', strtotime($l['data'])), $l['nome'], $l['substituiu'] ?? '', $l['turno'] ?? '', $esp ? 'Dom/Feriado' : 'Semana', $l['tipo'] === 'Extra' || $sub_soma ? csv_num($l['valor']) : 'Incluso no quadro'];
    }
    $linhas_quadro[] = [];
    if ($sub_soma) {
        $linhas_quadro[] = ['Subtotal substituições', '', '', '', '', '', csv_num($totais['sub'])];
    }
    $linhas_quadro[] = ['Subtotal extras', '', '', '', '', '', csv_num($totais['extra'])];
    $linhas_quadro[] = ['TOTAL', '', '', '', '', '', csv_num($totais['geral'])];
    exportar_csv($nome_arquivo, ['Colaborador', 'Operação', 'Valor mensal', 'Valor p/ dia útil', 'Dias trabalhados', 'Faltas', 'Desconto de faltas', 'Dom/Feriado (dias)', 'Dom/Feriado (R$)', 'Valor'], $linhas_quadro);
}

// ---------------------------------------------------------------------------
// Controle de documentos
// ---------------------------------------------------------------------------

// Dias de antecedência para avisar que a validade de um documento está acabando
const DOCUMENTO_AVISO_DIAS = 7;

function tipos_documento(PDO $pdo) {
    return $pdo->query("SELECT id, nome, exigido FROM documentos_tipos ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

// Documentos exigidos de cada colaborador: [funcionario_id => [[nome, entregue, vencimento], ...]]
function documentos_exigidos(PDO $pdo) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $sql = "SELECT f.id AS funcionario_id, t.nome, d.funcionario_id IS NOT NULL AS entregue, d.data_vencimento
                FROM funcionarios f
                CROSS JOIN documentos_tipos t
                LEFT JOIN funcionario_documentos d ON d.funcionario_id = f.id AND d.tipo_id = t.id
                WHERE t.exigido = 1
                ORDER BY t.id";
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cache[$r['funcionario_id']][] = $r;
        }
    }
    return $cache;
}

// O que impede o colaborador de ser lançado no dia: documento exigido não entregue ou vencido antes do dia.
// Lista vazia = documentação em dia.
function problemas_documentos(PDO $pdo, $funcionario_id, $dia) {
    $problemas = [];
    foreach (documentos_exigidos($pdo)[$funcionario_id] ?? [] as $doc) {
        if (!$doc['entregue'] || !$doc['data_vencimento']) {
            $problemas[] = $doc['nome'] . ' pendente';
        } elseif ($doc['data_vencimento'] && $doc['data_vencimento'] < $dia) {
            $problemas[] = $doc['nome'] . ' vencido em ' . date('d/m/Y', strtotime($doc['data_vencimento']));
        }
    }
    return $problemas;
}

// Dias do período (até hoje) em que algum funcionário do quadro ficou sem frequência lançada.
// Retorna [data => [nomes]]. Considera só quem estava ativo no dia (cadastro e inativação).
// Funcionário com documentação irregular também precisa ser lançado (como Bloqueado ou Substituído).
// Com filial, só os funcionários dela; funcionário ainda sem filial não entra (não tem folha para ser lançado).
function buscar_pendencias(PDO $pdo, $empresa_id, $data_inicio, $data_fim, $funcionario_id = 0, $filial_id = 0) {
    $fim = min($data_fim, date('Y-m-d'));
    if (!$empresa_id || !$data_inicio || $data_inicio > $fim) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT id, nome, status, data_inativacao, data_cadastro FROM funcionarios
                           WHERE empresa_id = :empresa_id AND tipo = 'Funcionario' AND filial_id IS NOT NULL
                             " . sql_funcionarios('id', $funcionario_id) . sql_filial('filial_id', $filial_id) . "
                           ORDER BY nome ASC");
    $stmt->execute([':empresa_id' => $empresa_id]);
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT c.funcionario_id, c.data_referencia FROM controle_assiduidade c
                           INNER JOIN funcionarios f ON f.id = c.funcionario_id
                           WHERE f.empresa_id = :empresa_id AND c.data_referencia BETWEEN :ini AND :fim
                           UNION
                           SELECT c.funcionario_id, c.data_referencia FROM controle_assiduidade c
                           WHERE c.empresa_id = :empresa_id2 AND c.data_referencia BETWEEN :ini2 AND :fim2");
    $stmt->execute([':empresa_id' => $empresa_id, ':ini' => $data_inicio, ':fim' => $fim, ':empresa_id2' => $empresa_id, ':ini2' => $data_inicio, ':fim2' => $fim]);
    $lancados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lancados[$r['funcionario_id']][$r['data_referencia']] = true;
    }

    $pendencias = [];
    $periodo = new DatePeriod(new DateTime($data_inicio), new DateInterval('P1D'), (new DateTime($fim))->modify('+1 day'));
    foreach ($periodo as $d) {
        $dia = $d->format('Y-m-d');
        foreach ($funcionarios as $f) {
            if ($f['data_cadastro'] && $dia < $f['data_cadastro']) {
                continue;
            }
            if ((int)$f['status'] !== 1 && (!$f['data_inativacao'] || $dia >= $f['data_inativacao'])) {
                continue;
            }
            if (!isset($lancados[$f['id']][$dia])) {
                $pendencias[$dia][] = $f['nome'];
            }
        }
    }
    return $pendencias;
}

// Dias que ficaram sem frequência lançada, para o sininho da barra superior
const NOTIFICACAO_DIAS = 30;

// Últimos 30 dias (o último mês) até ontem (o dia de hoje ainda pode ser lançado), do mais recente para o mais antigo.
// Cada notificação é um dia + empresa + filial (a supervisão só recebe as da filial dela).
// Retorna [data => [[empresa_id, empresa, filial_id, filial, quantidade de funcionários sem lançamento], ...]]
function dias_sem_lancamento(PDO $pdo) {
    $inicio = date('Y-m-d', strtotime('-' . NOTIFICACAO_DIAS . ' days'));
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $filiais = lista_filiais($pdo);
    if (filial_do_usuario() !== null) {
        $filiais = array_filter($filiais, fn($f) => (int)$f['id'] === filial_do_usuario());
    }
    $dias = [];
    foreach (lista_empresas($pdo) as $emp) {
        foreach ($filiais as $fil) {
            foreach (buscar_pendencias($pdo, $emp['id'], $inicio, $ontem, 0, $fil['id']) as $data => $nomes) {
                $dias[$data][] = ['empresa_id' => (int)$emp['id'], 'empresa' => $emp['razao_social'], 'filial_id' => (int)$fil['id'], 'filial' => $fil['nome'], 'quantidade' => count($nomes)];
            }
        }
    }
    krsort($dias);
    return $dias;
}

// Marca como aberta a notificação de um dia/empresa/filial para o usuário logado (ao abrir a folha daquele dia)
function marcar_notificacao_vista(PDO $pdo, $empresa_id, $filial_id, $data) {
    $usuario = usuario_atual();
    if (!$usuario || !$empresa_id || !$filial_id || !DateTime::createFromFormat('Y-m-d', (string)$data)) {
        return;
    }
    $pdo->prepare("INSERT IGNORE INTO notificacoes_vistas (usuario_id, empresa_id, filial_id, data_referencia) VALUES (:usuario_id, :empresa_id, :filial_id, :data_ref)")
        ->execute([':usuario_id' => $usuario['id'], ':empresa_id' => (int)$empresa_id, ':filial_id' => (int)$filial_id, ':data_ref' => $data]);
    // Fora da janela do sininho a marcação não serve mais
    $pdo->prepare("DELETE FROM notificacoes_vistas WHERE usuario_id = :usuario_id AND data_referencia < :limite")
        ->execute([':usuario_id' => $usuario['id'], ':limite' => date('Y-m-d', strtotime('-' . NOTIFICACAO_DIAS . ' days'))]);
}

// Sininho com os dias sem lançamento (só para quem pode lançar frequência).
// O número conta só as notificações que o usuário ainda não abriu; as abertas continuam na lista, em cinza, até o dia ser lançado.
function render_notificacoes() {
    $pdo = $GLOBALS['pdo'] ?? null;
    $usuario = usuario_atual();
    if (!$pdo || !$usuario || !pode_acessar('lancar_frequencia.php')) {
        return;
    }
    $dias = dias_sem_lancamento($pdo);
    $stmt = $pdo->prepare("SELECT CONCAT(data_referencia, '|', empresa_id, '|', filial_id) FROM notificacoes_vistas WHERE usuario_id = :usuario_id");
    $stmt->execute([':usuario_id' => $usuario['id']]);
    $vistas = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    $nao_vistas = 0;
    foreach ($dias as $data => $empresas) {
        foreach ($empresas as $item) {
            $nao_vistas += isset($vistas[$data . '|' . $item['empresa_id'] . '|' . $item['filial_id']]) ? 0 : 1;
        }
    }
    $semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    ?>
    <div class="dropdown">
        <button class="app-sino<?php echo $nao_vistas ? ' tem-aviso' : ''; ?>" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                title="<?php echo $nao_vistas ? $nao_vistas . ($nao_vistas === 1 ? ' notificação não aberta' : ' notificações não abertas') : ($dias ? 'Nenhuma notificação nova' : 'Nenhuma pendência de frequência'); ?>">
            <i class="bi bi-bell<?php echo $nao_vistas ? '-fill' : ''; ?>"></i>
            <?php if ($nao_vistas): ?><span class="app-sino-contador"><?php echo $nao_vistas > 99 ? '99+' : $nao_vistas; ?></span><?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow app-notificacoes p-0">
            <div class="app-notificacoes-topo">
                <strong><i class="bi bi-bell me-1"></i>Frequência não lançada</strong>
                <?php if ($nao_vistas): ?><span class="badge-app badge-danger"><?php echo $nao_vistas . ($nao_vistas === 1 ? ' nova' : ' novas'); ?></span><?php endif; ?>
            </div>
            <?php if (!$dias): ?>
                <div class="app-notificacoes-vazio"><i class="bi bi-check-circle"></i>Tudo em dia! Nenhum dia sem lançamento.</div>
            <?php else: ?>
                <div class="app-notificacoes-lista">
                    <?php foreach ($dias as $data => $empresas): $ts = strtotime($data); ?>
                        <?php foreach ($empresas as $item): $vista = isset($vistas[$data . '|' . $item['empresa_id'] . '|' . $item['filial_id']]); ?>
                        <a class="app-notificacao<?php echo $vista ? ' vista' : ''; ?>" href="lancar_frequencia.php?data_referencia=<?php echo e($data); ?>&empresa_id=<?php echo $item['empresa_id']; ?>&filial_id=<?php echo $item['filial_id']; ?>">
                            <span class="app-notificacao-data">
                                <span class="dia"><?php echo date('d/m', $ts); ?></span>
                                <span class="semana"><?php echo $semana[(int)date('w', $ts)]; ?></span>
                            </span>
                            <span class="app-notificacao-texto">
                                <span class="d-block fw-semibold text-truncate"><?php echo e($item['empresa']); ?></span>
                                <span class="d-block small text-muted text-truncate"><i class="bi bi-geo-alt me-1"></i><?php echo e($item['filial']); ?></span>
                                <span class="d-block small app-notificacao-detalhe"><?php echo $item['quantidade'] === 1 ? '1 funcionário sem lançamento' : $item['quantidade'] . ' funcionários sem lançamento'; ?><?php echo $vista ? ' · aberta' : ''; ?></span>
                            </span>
                            <?php if (!$vista): ?><span class="app-notificacao-nova" title="Não aberta"></span><?php endif; ?>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <div class="app-notificacoes-rodape small text-muted">Últimos <?php echo NOTIFICACAO_DIAS; ?> dias · clique em um dia para lançar.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Aviso que bloqueia o relatório/cálculo enquanto houver dias sem frequência lançada:
// abre uma janela de alerta ao carregar a página e mantém o resumo na tela
function render_pendencias(array $pendencias, $empresa_id, $acao, $filial_id = 0) {
    $total = count($pendencias);
    $primeiro = array_key_first($pendencias);
    $link = fn($dia) => 'lancar_frequencia.php?empresa_id=' . (int)$empresa_id . '&data_referencia=' . $dia . ($filial_id ? '&filial_id=' . (int)$filial_id : '');
    $semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    $texto_dias = $total . ' dia' . ($total > 1 ? 's' : '');
    ?>
    <style>
        .alerta-pendencia { --cor-alerta: #d92d20; color: var(--cor-alerta); }
        .alerta-pendencia .titulo { color: var(--cor-alerta) !important; font-weight: 700; }
        .alerta-pendencia .icone { width: 52px; height: 52px; border-radius: 50%; background: #fee4e2; color: var(--cor-alerta); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0; }
        .alerta-pendencia .lista-pend { max-height: 260px; overflow-y: auto; border: 1px solid #fecdca; border-radius: 8px; }
        .alerta-pendencia .lista-pend table td, .alerta-pendencia .lista-pend table th { color: var(--cor-alerta); }
        .alerta-pendencia .lista-pend thead th { background: #fef3f2 !important; position: sticky; top: 0; }
        .alerta-pendencia .btn-alerta { background: var(--cor-alerta); border-color: var(--cor-alerta); color: #fff; font-weight: 600; }
        .alerta-pendencia .btn-alerta:hover { background: #b42318; color: #fff; }
        .card-pendencia { border: 1px solid #fecdca !important; background: #fef3f2; box-shadow: inset 4px 0 0 #d92d20; }
    </style>

    <!-- Resumo fixo na página -->
    <div class="card card-custom card-pendencia alerta-pendencia p-4 mb-4">
        <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
            <div class="d-flex gap-3 align-items-center">
                <span class="icone"><i class="bi bi-exclamation-octagon-fill"></i></span>
                <div>
                    <h5 class="titulo m-0 mb-1">Não é possível <?php echo e($acao); ?></h5>
                    <div>Há <strong><?php echo $texto_dias; ?></strong> no período com frequência pendente.</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-light-app" data-bs-toggle="modal" data-bs-target="#modalPendencias"><i class="bi bi-list-ul"></i>Ver pendências</button>
                <a href="<?php echo e($link($primeiro)); ?>" class="btn btn-alerta text-nowrap"><i class="bi bi-pencil-square"></i>Corrigir pendências</a>
            </div>
        </div>
    </div>

    <!-- Janela de alerta -->
    <div class="modal fade alerta-pendencia" id="modalPendencias" tabindex="-1" aria-labelledby="modalPendenciasTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0" style="border-top: 6px solid #d92d20 !important; border-radius: 12px;">
                <div class="modal-header border-0 pb-0">
                    <div class="d-flex gap-3 align-items-center">
                        <span class="icone"><i class="bi bi-exclamation-octagon-fill"></i></span>
                        <div>
                            <h4 class="titulo modal-title m-0" id="modalPendenciasTitulo">Atenção! Frequência pendente</h4>
                            <div class="fw-semibold">Não é possível <?php echo e($acao); ?>.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Existem <strong><?php echo $texto_dias; ?></strong> no período selecionado sem frequência lançada. Lance a frequência desses dias para liberar esta ação.</p>
                    <div class="lista-pend">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3" style="width: 130px;">Data</th>
                                    <th>Funcionários sem lançamento</th>
                                    <th style="width: 100px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendencias as $dia => $nomes): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-nowrap"><?php echo date('d/m/Y', strtotime($dia)); ?> <small><?php echo $semana[date('w', strtotime($dia))]; ?></small></td>
                                    <td><?php echo e(implode(', ', $nomes)); ?></td>
                                    <td class="text-end pe-3"><a href="<?php echo e($link($dia)); ?>" class="btn btn-sm btn-light-app"><i class="bi bi-arrow-right"></i>Lançar</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Fechar</button>
                    <a href="<?php echo e($link($primeiro)); ?>" class="btn btn-alerta"><i class="bi bi-pencil-square"></i>Corrigir pendências</a>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Abre a janela de alerta assim que a página carregar
        window.addEventListener('load', function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPendencias')).show();
        });
    </script>
    <?php
}
