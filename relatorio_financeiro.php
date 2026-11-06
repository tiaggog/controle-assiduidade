<?php
/**
 * Relatório financeiro por horas (versão antiga)
 *
 * Cálculo antigo por valor da hora normal e da hora extra. Não aparece mais no menu:
 * o faturamento passou a ser feito pela Pré-Fatura (por dias trabalhados e Tabela de Valores).
 * Mantido apenas para consulta; o acesso segue as regras do grupo Financeiro.
 */
require_once 'conexao.php';

$data_inicio = $_POST['data_inicio'] ?? null;
$data_fim    = $_POST['data_fim'] ?? null;
$empresa_id  = $_POST['empresa_id'] ?? null;

$valor_hn = isset($_POST['valor_hora_normal']) && $_POST['valor_hora_normal'] !== '' ? (float)$_POST['valor_hora_normal'] : 0.0;
$valor_he = isset($_POST['valor_hora_extra']) && $_POST['valor_hora_extra'] !== '' ? (float)$_POST['valor_hora_extra'] : 0.0;

$resultados = [];

if (!$data_inicio || !$data_fim || !$empresa_id) {
    header('Location: calcular_faturamento.php');
    exit;
}

$filial_id = filial_filtrada();

// Dias sem frequência lançada bloqueiam o fechamento
$pendencias = buscar_pendencias($pdo, $empresa_id, $data_inicio, $data_fim, 0, $filial_id);
if ($pendencias) {
    render_cabecalho('Demonstrativo de Faturamento', 'Consolidação das horas normais e extras do quadro de funcionários no período.');
    ?>
    <div class="mb-3"><a href="calcular_faturamento.php" class="btn btn-light-app btn-sm"><i class="bi bi-arrow-left"></i>Voltar ao fechamento</a></div>
    <?php
    render_pendencias($pendencias, $empresa_id, 'calcular o faturamento', $filial_id);
    render_rodape();
    exit;
}

try {
    $sql = "SELECT
                f.nome AS funcionario,
                f.carga_horaria,
                SUM(CASE WHEN c.presenca = 'Justificado' THEN f.carga_horaria
                         WHEN c.presenca = 'Presente' AND c.horas_trabalhadas <= f.carga_horaria THEN c.horas_trabalhadas
                         WHEN c.presenca = 'Presente' AND c.horas_trabalhadas > f.carga_horaria THEN f.carga_horaria
                         ELSE 0.00 END) AS total_hn,
                SUM(CASE WHEN c.presenca = 'Presente' AND c.horas_trabalhadas > f.carga_horaria THEN (c.horas_trabalhadas - f.carga_horaria)
                         ELSE 0.00 END) AS total_he
            FROM funcionarios f
            INNER JOIN controle_assiduidade c ON f.id = c.funcionario_id
            WHERE c.empresa_id = :empresa_id" . sql_filial('c.filial_id', $filial_id) . "
              AND c.data_referencia BETWEEN :data_inicio AND :data_fim
            GROUP BY f.id, f.nome, f.carga_horaria
            ORDER BY f.nome ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa_id' => $empresa_id,
        ':data_inicio' => $data_inicio,
        ':data_fim' => $data_fim
    ]);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao processar banco de dados: " . $e->getMessage());
}

$empresas = lista_empresas($pdo);

$totais = ['hn' => 0, 'he' => 0, 'vhn' => 0, 'vhe' => 0];
foreach ($resultados as $linha) {
    $totais['hn'] += $linha['total_hn'];
    $totais['he'] += $linha['total_he'];
    $totais['vhn'] += $linha['total_hn'] * $valor_hn;
    $totais['vhe'] += $linha['total_he'] * $valor_he;
}
$total_geral_fatura = $totais['vhn'] + $totais['vhe'];

render_cabecalho('Demonstrativo de Faturamento', 'Consolidação das horas normais e extras do quadro de funcionários no período.');
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <a href="calcular_faturamento.php" class="btn btn-light-app btn-sm"><i class="bi bi-arrow-left"></i>Novo fechamento</a>
    <button type="button" class="btn btn-light-app btn-sm" onclick="window.print()"><i class="bi bi-printer"></i>Imprimir / PDF</button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-clock"></i>Taxa HN</div><div class="metric-value"><?php echo fmt_moeda($valor_hn); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-clock-history"></i>Taxa HE</div><div class="metric-value"><?php echo fmt_moeda($valor_he); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-stopwatch"></i>Horas faturadas</div><div class="metric-value"><?php echo fmt_horas($totais['hn'] + $totais['he']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card is-primary"><div class="metric-label"><i class="bi bi-cash-stack"></i>Total da fatura</div><div class="metric-value"><?php echo fmt_moeda($total_geral_fatura); ?></div></div></div>
</div>

<div class="card card-custom overflow-hidden">
    <div class="p-3 px-4 border-bottom">
        <h4 class="m-0">Demonstrativo <span class="text-muted fw-normal">· <?php echo e(nome_empresa($empresas, $empresa_id)); ?><?php echo $filial_id ? ' · ' . e(nome_filial(lista_filiais($pdo, false), $filial_id)) : ''; ?></span></h4>
        <div class="text-muted small mt-1"><i class="bi bi-calendar-range me-1"></i>Competência: <?php echo date('d/m/Y', strtotime($data_inicio)); ?> a <?php echo date('d/m/Y', strtotime($data_fim)); ?></div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th class="num">Carga diária</th>
                    <th class="num">Horas normais</th>
                    <th class="num">Subtotal HN</th>
                    <th class="num">Horas extras</th>
                    <th class="num">Subtotal HE</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($resultados) > 0): ?>
                    <?php foreach ($resultados as $linha):
                        $subtotal_hn = $linha['total_hn'] * $valor_hn;
                        $subtotal_he = $linha['total_he'] * $valor_he;
                    ?>
                    <tr>
                        <td class="fw-medium"><?php echo e($linha['funcionario']); ?></td>
                        <td class="num text-muted"><?php echo fmt_horas($linha['carga_horaria']); ?></td>
                        <td class="num"><?php echo fmt_horas($linha['total_hn']); ?></td>
                        <td class="num"><?php echo fmt_moeda($subtotal_hn); ?></td>
                        <td class="num"><?php echo fmt_horas($linha['total_he']); ?></td>
                        <td class="num"><?php echo fmt_moeda($subtotal_he); ?></td>
                        <td class="num fw-semibold"><?php echo fmt_moeda($subtotal_hn + $subtotal_he); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="table-empty"><i class="bi bi-inbox"></i>Nenhum registro encontrado para a consolidação deste faturamento.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($resultados): ?>
            <tfoot>
                <tr>
                    <td colspan="2">Total consolidado</td>
                    <td class="num"><?php echo fmt_horas($totais['hn']); ?></td>
                    <td class="num"><?php echo fmt_moeda($totais['vhn']); ?></td>
                    <td class="num"><?php echo fmt_horas($totais['he']); ?></td>
                    <td class="num"><?php echo fmt_moeda($totais['vhe']); ?></td>
                    <td class="num" style="color: var(--app-primary)"><?php echo fmt_moeda($total_geral_fatura); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php render_rodape(); ?>
