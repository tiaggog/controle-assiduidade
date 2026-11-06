<?php
require_once 'conexao.php';

// Fatura salva a partir da Pré-Fatura: mostra os valores exatamente como foram salvos
$stmt = $pdo->prepare("SELECT fs.*, e.razao_social, fl.nome AS filial_nome, u.nome AS usuario_nome
                       FROM faturas_salvas fs
                       LEFT JOIN empresas_terceirizadas e ON e.id = fs.empresa_id
                       LEFT JOIN filiais fl ON fl.id = fs.filial_id
                       LEFT JOIN usuarios u ON u.id = fs.usuario_id
                       WHERE fs.id = :id" . sql_filial('fs.filial_id', filial_do_usuario()));
$stmt->execute([':id' => (int)($_GET['id'] ?? 0)]);
$fat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fat) {
    render_cabecalho('Fatura salva');
    echo alerta('warning', 'Fatura não encontrada. <a href="calcular_faturamento.php#faturas">Voltar ao histórico</a>.');
    render_rodape();
    exit;
}

$dados = json_decode($fat['dados'], true) ?: [];
$func = $dados['func'] ?? [];
$diar = $dados['diar'] ?? [];
$totais = [
    'func' => (float)$fat['total_func'], 'sub' => (float)$fat['total_sub'], 'extra' => (float)$fat['total_extra'],
    'geral' => (float)$fat['total_geral'], 'he' => (float)$fat['total_he'], 'dias' => (int)($dados['dias'] ?? 0),
];

if (($_GET['formato'] ?? '') === 'csv') {
    exportar_csv_fatura('fatura_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $fat['codigo']) . '.csv', $func, $diar, $totais);
}

$escopo = ($fat['razao_social'] ?? '—') . ' · ' . ($fat['filial_nome'] ?? 'Todas as filiais');
render_cabecalho('Fatura ' . $fat['codigo'], 'Pré-fatura salva em ' . date('d/m/Y H:i', strtotime($fat['criado_em'])) . ($fat['usuario_nome'] ? ' por ' . $fat['usuario_nome'] : '') . '.');
?>

<div class="mb-3 no-print">
    <a href="calcular_faturamento.php#faturas" class="btn btn-light-app btn-sm"><i class="bi bi-arrow-left"></i>Voltar ao histórico</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-people"></i>Quadro de funcionários</div><div class="metric-value"><?php echo fmt_moeda($totais['func']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-arrow-left-right"></i>Substituições</div><div class="metric-value"><?php $qtd_sub = count(array_filter($diar, fn($l) => $l['tipo'] !== 'Extra')); echo $qtd_sub; ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-person-plus"></i>Extras</div><div class="metric-value"><?php echo fmt_moeda($totais['extra']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card is-primary"><div class="metric-label"><i class="bi bi-receipt"></i>Total da fatura</div><div class="metric-value"><?php echo fmt_moeda($totais['geral']); ?></div></div></div>
</div>

<?php
render_tabelas_fatura($func, $diar, $totais, $escopo, $fat['data_inicio'], $fat['data_fim'],
    ($fat['funcionario_nome'] ? '<i class="bi bi-person me-1"></i>' . e($fat['funcionario_nome']) . '<span class="mx-2">|</span>' : '')
    . (int)$fat['dias_uteis'] . ' dias úteis · ' . $totais['dias'] . ' dias trabalhados',
    'Total da fatura');
?>

<?php render_rodape(); ?>
