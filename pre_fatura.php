<?php
require_once 'conexao.php';

$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim    = $_GET['data_fim'] ?? date('Y-m-d');
$empresa_id  = $_GET['empresa_id'] ?? null;
// Dias úteis do período (base do proporcional): digitado na tela, com sugestão de segunda a sábado
$dias_uteis_sugeridos = sugerir_dias_uteis($data_inicio, $data_fim);
$dias_uteis = isset($_GET['dias_uteis']) && $_GET['dias_uteis'] !== '' ? max(1, min(31, (int)$_GET['dias_uteis'])) : $dias_uteis_sugeridos;

$empresas = lista_empresas($pdo);
$linhas_func = $linhas_diar = $avisos = [];
$totais = ['func' => 0, 'sub' => 0, 'extra' => 0, 'dias' => 0, 'he' => 0, 'geral' => 0];

// Filtro por funcionário: só ele e as substituições feitas no lugar dele (extras não pertencem a nenhum funcionário)
$funcionarios_filtro = lista_funcionarios_filtro($pdo);
$funcionario_id = funcionarios_filtrados(); // um ou vários (vazio = todos)
$nome_funcionario = nomes_funcionarios($funcionarios_filtro, $funcionario_id, 5);

$filial_id = filial_filtrada();
$pendencias = buscar_pendencias($pdo, $empresa_id, $data_inicio, $data_fim, $funcionario_id, $filial_id); // dias sem frequência bloqueiam o cálculo

if ($empresa_id && $data_inicio && $data_fim && !$pendencias) {
    [$linhas_func, $linhas_diar, $totais, $avisos] = calcular_pre_fatura($pdo, $empresa_id, $data_inicio, $data_fim, $funcionario_id, $filial_id, $dias_uteis);
}
$total_geral = $totais['geral'];

// Salvar a pré-fatura com um nome/código: vai para o histórico do Faturamento
$msg_salvar = '';
$codigo_digitado = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_fatura') {
    $codigo_digitado = trim((string)($_POST['codigo'] ?? ''));
    if (!$empresa_id || $pendencias) {
        $msg_salvar = alerta('danger', 'Não é possível salvar: escolha a empresa e lance a frequência dos dias pendentes.');
    } elseif ($codigo_digitado === '' || mb_strlen($codigo_digitado) > 80) {
        $msg_salvar = alerta('danger', 'Informe um nome ou código para a fatura (até 80 caracteres).');
    } else {
        $stmt = $pdo->prepare("SELECT id FROM faturas_salvas WHERE codigo = :codigo");
        $stmt->execute([':codigo' => $codigo_digitado]);
        if ($stmt->fetchColumn()) {
            $msg_salvar = alerta('danger', 'Já existe uma fatura salva com o nome/código <strong>' . e($codigo_digitado) . '</strong>. Use outro.');
        } else {
            $dados = ['versao' => 2, 'func' => $linhas_func, 'diar' => $linhas_diar, 'he' => $totais['he'], 'dias' => $totais['dias'], 'avisos' => $avisos];
            $stmt = $pdo->prepare("INSERT INTO faturas_salvas (codigo, empresa_id, filial_id, funcionario_id, funcionario_nome, data_inicio, data_fim, dias_uteis,
                                          total_he, total_func, total_sub, total_extra, total_geral, dados, usuario_id)
                                   VALUES (:codigo, :empresa_id, :filial_id, :funcionario_id, :funcionario_nome, :ini, :fim, :dias_uteis,
                                          :the, :tfunc, :tsub, :textra, :tgeral, :dados, :usuario_id)");
            $stmt->execute([
                ':codigo' => $codigo_digitado, ':empresa_id' => (int)$empresa_id, ':filial_id' => $filial_id ?: null,
                ':funcionario_id' => count($funcionario_id) === 1 ? $funcionario_id[0] : null, ':funcionario_nome' => $nome_funcionario ? mb_substr($nome_funcionario, 0, 100) : null,
                ':ini' => $data_inicio, ':fim' => $data_fim, ':dias_uteis' => $dias_uteis,
                ':the' => $totais['he'], ':tfunc' => $totais['func'], ':tsub' => $totais['sub'],
                ':textra' => $totais['extra'], ':tgeral' => $total_geral,
                ':dados' => json_encode($dados, JSON_UNESCAPED_UNICODE), ':usuario_id' => usuario_atual()['id'] ?? null,
            ]);
            header('Location: calcular_faturamento.php?salva=' . (int)$pdo->lastInsertId());
            exit;
        }
    }
}

if (($_GET['formato'] ?? '') === 'csv' && $empresa_id && !$pendencias) {
    exportar_csv_fatura('pre_fatura_' . $data_inicio . '_a_' . $data_fim . '.csv', $linhas_func, $linhas_diar, $totais);
}

render_cabecalho('Pré-Fatura', 'Prévia do faturamento por dias trabalhados: valor da Tabela de Valores ÷ dias úteis × dias trabalhados.');
?>

<?php render_filtro_periodo($empresas, $data_inicio, $data_fim, $empresa_id,
    ['dias_uteis' => ['rotulo' => 'Dias úteis', 'sufixo' => 'dias', 'valor' => $dias_uteis_sugeridos, 'ajuda' => 'Sugestão: ' . $dias_uteis_sugeridos . ' (seg. a sáb.)']],
    $funcionarios_filtro); ?>

<?php echo $msg_salvar; ?>

<?php if ($empresa_id && $pendencias): render_pendencias($pendencias, $empresa_id, 'calcular a pré-fatura', $filial_id); elseif ($empresa_id): ?>

<?php if ($avisos): ?>
<div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-warning-soft); color: var(--app-warning); border: 1px solid #fedf89;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div><strong>Confira antes de salvar:</strong><ul class="mb-0 mt-1"><?php foreach ($avisos as $aviso): ?><li><?php echo $aviso; ?></li><?php endforeach; ?></ul></div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3 no-print">
    <button type="button" class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalSalvarFatura">
        <i class="bi bi-floppy"></i>Salvar pré-fatura
    </button>
</div>

<div class="modal fade" id="modalSalvarFatura" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="?<?php echo e(http_build_query($_GET)); ?>" class="modal-content">
            <input type="hidden" name="acao" value="salvar_fatura">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-floppy me-2"></i>Salvar pré-fatura</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="codigo_fatura">Nome ou código da fatura</label>
                <input type="text" name="codigo" id="codigo_fatura" class="form-control" maxlength="80" required value="<?php echo e($codigo_digitado); ?>" placeholder="Ex.: FAT-2026-001 ou Outubro Fortaleza">
                <div class="small text-muted mt-3">
                    <?php echo e(nome_empresa($empresas, $empresa_id)); ?><?php echo $filial_id ? ' · ' . e(nome_filial(lista_filiais($pdo, false), $filial_id)) : ''; ?>
                    · <?php echo date('d/m/Y', strtotime($data_inicio)); ?> a <?php echo date('d/m/Y', strtotime($data_fim)); ?>
                    · <?php echo $dias_uteis; ?> dias úteis
                    · <strong><?php echo fmt_moeda($total_geral); ?></strong>
                </div>
                <?php if ($avisos): ?><div class="small mt-2" style="color: var(--app-warning)"><i class="bi bi-exclamation-triangle-fill me-1"></i>Há avisos nesta pré-fatura (confira no topo da página).</div><?php endif; ?>
                <div class="small text-muted mt-1">Os valores ficam guardados como estão agora e aparecem no histórico do <a href="calcular_faturamento.php">Faturamento</a>.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light-app" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check-lg"></i>Salvar</button>
            </div>
        </form>
    </div>
</div>
<?php if ($msg_salvar): ?>
<script>document.addEventListener('DOMContentLoaded', function () { const m = document.getElementById('modalSalvarFatura'); if (m) bootstrap.Modal.getOrCreateInstance(m).show(); });</script>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-people"></i>Quadro de funcionários</div><div class="metric-value"><?php echo fmt_moeda($totais['func']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-arrow-left-right"></i>Substituições</div><div class="metric-value"><?php $qtd_sub = count(array_filter($linhas_diar, fn($l) => $l['tipo'] !== 'Extra')); echo $qtd_sub; ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-person-plus"></i>Extras</div><div class="metric-value"><?php echo fmt_moeda($totais['extra']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card is-primary"><div class="metric-label"><i class="bi bi-receipt"></i>Total da pré-fatura</div><div class="metric-value"><?php echo fmt_moeda($total_geral); ?></div></div></div>
</div>

<?php
render_tabelas_fatura($linhas_func, $linhas_diar, $totais,
    nome_empresa($empresas, $empresa_id) . ($filial_id ? ' · ' . nome_filial(lista_filiais($pdo, false), $filial_id) : ''),
    $data_inicio, $data_fim,
    ($nome_funcionario ? '<i class="bi bi-person me-1"></i>' . e($nome_funcionario) . '<span class="mx-2">|</span>' : '') . $dias_uteis . ' dias úteis · ' . (int)$totais['dias'] . ' dias trabalhados',
    'Total da pré-fatura');
?>
<?php endif; ?>

<?php render_rodape(); ?>
