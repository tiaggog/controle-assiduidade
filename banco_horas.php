<?php
require_once 'conexao.php';

$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim    = $_GET['data_fim'] ?? date('Y-m-d');
$empresa_id  = $_GET['empresa_id'] ?? null;

$empresas = lista_empresas($pdo);
$filial_id = filial_filtrada();

// Dias sem frequência lançada bloqueiam a consulta
$pendencias = buscar_pendencias($pdo, $empresa_id, $data_inicio, $data_fim, funcionarios_filtrados(), $filial_id);
$resultados = [];

// Regras do banco de horas (por dia lançado):
//  Presente    -> crédito do que passar da carga, débito do que faltar para a carga
//  Falta       -> débito da carga diária inteira
//  Justificado -> conta como dia normal: carga padrão trabalhada (não gera crédito nem débito)
//  Bloqueado por documentação e substituído -> não conta (nem previsto nem débito)
//  Bloqueado por documentação sem lançamento -> falta: débito da carga diária inteira
if ($empresa_id && $data_inicio && $data_fim && !$pendencias) {
    $sql = "SELECT f.id, f.nome, f.carga_horaria, f.status,
                   SUM(c.presenca = 'Presente') AS dias_presente,
                   SUM(c.presenca = 'Falta' AND NOT (c.doc_irregular IS NOT NULL AND EXISTS (SELECT 1 FROM lancamentos_diaristas ld WHERE ld.substituido_id = c.funcionario_id AND ld.data_referencia = c.data_referencia AND ld.empresa_id = c.empresa_id))) AS dias_falta,
                   SUM(c.presenca = 'Justificado') AS dias_justificado,
                   SUM(c.doc_irregular IS NOT NULL) AS dias_doc,
                   SUM(CASE WHEN c.presenca = 'Presente' THEN c.horas_trabalhadas WHEN c.presenca = 'Justificado' THEN f.carga_horaria ELSE 0 END) AS horas_trabalhadas,
                   SUM(CASE WHEN c.presenca = 'Falta' AND c.doc_irregular IS NOT NULL AND EXISTS (SELECT 1 FROM lancamentos_diaristas ld WHERE ld.substituido_id = c.funcionario_id AND ld.data_referencia = c.data_referencia AND ld.empresa_id = c.empresa_id) THEN 0 ELSE f.carga_horaria END) AS horas_previstas,
                   SUM(CASE WHEN c.presenca = 'Presente' AND c.horas_trabalhadas > f.carga_horaria THEN c.horas_trabalhadas - f.carga_horaria ELSE 0 END) AS creditos,
                   SUM(CASE WHEN c.presenca = 'Presente' AND c.horas_trabalhadas < f.carga_horaria THEN f.carga_horaria - c.horas_trabalhadas
                            WHEN c.presenca = 'Falta' AND NOT (c.doc_irregular IS NOT NULL AND EXISTS (SELECT 1 FROM lancamentos_diaristas ld WHERE ld.substituido_id = c.funcionario_id AND ld.data_referencia = c.data_referencia AND ld.empresa_id = c.empresa_id)) THEN f.carga_horaria ELSE 0 END) AS debitos
            FROM funcionarios f
            INNER JOIN controle_assiduidade c ON c.funcionario_id = f.id
            WHERE c.empresa_id = :empresa_id" . sql_filial('c.filial_id', $filial_id) . "
              AND c.data_referencia BETWEEN :ini AND :fim
              " . sql_funcionarios('f.id', funcionarios_filtrados()) . "
            GROUP BY f.id, f.nome, f.carga_horaria, f.status
            ORDER BY f.nome ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa_id' => $empresa_id, ':ini' => $data_inicio, ':fim' => $data_fim]);
    $resultados = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');

    // Bloqueado por documentação e não substituído (dia sem lançamento): conta como falta, débito da carga
    $stmt = $pdo->prepare("SELECT id, nome, carga_horaria, status, data_cadastro, data_inativacao FROM funcionarios
                           WHERE empresa_id = :empresa_id AND tipo = 'Funcionario' AND filial_id IS NOT NULL
                             " . sql_funcionarios('id', funcionarios_filtrados()) . sql_filial('filial_id', $filial_id));
    $stmt->execute([':empresa_id' => $empresa_id]);
    $stmt_lanc = $pdo->prepare("SELECT data_referencia FROM controle_assiduidade WHERE funcionario_id = :id AND data_referencia BETWEEN :ini AND :fim");
    $ate = min($data_fim, date('Y-m-d'));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fu) {
        $stmt_lanc->execute([':id' => $fu['id'], ':ini' => $data_inicio, ':fim' => $ate]);
        $lancados = array_fill_keys($stmt_lanc->fetchAll(PDO::FETCH_COLUMN), true);
        $bloqueados = 0;
        for ($t = strtotime($data_inicio); $t <= strtotime($ate); $t = strtotime('+1 day', $t)) {
            $dia = date('Y-m-d', $t);
            if (isset($lancados[$dia]) || ($fu['data_cadastro'] && $dia < $fu['data_cadastro'])
                || ((int)$fu['status'] !== 1 && (!$fu['data_inativacao'] || $dia >= $fu['data_inativacao']))) {
                continue;
            }
            $bloqueados += problemas_documentos($pdo, $fu['id'], $dia) ? 1 : 0;
        }
        if (!$bloqueados) {
            continue;
        }
        $r = $resultados[$fu['id']] ?? ['id' => $fu['id'], 'nome' => $fu['nome'], 'carga_horaria' => $fu['carga_horaria'], 'status' => $fu['status'],
            'dias_presente' => 0, 'dias_falta' => 0, 'dias_justificado' => 0, 'dias_doc' => 0, 'horas_trabalhadas' => 0, 'horas_previstas' => 0, 'creditos' => 0, 'debitos' => 0];
        $r['dias_falta'] += $bloqueados;
        $r['dias_doc'] += $bloqueados;
        $r['horas_previstas'] += $bloqueados * (float)$fu['carga_horaria'];
        $r['debitos'] += $bloqueados * (float)$fu['carga_horaria'];
        $resultados[$fu['id']] = $r;
    }
    usort($resultados, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
}

if (($_GET['formato'] ?? '') === 'csv' && $empresa_id && !$pendencias) {
    $linhas = [];
    foreach ($resultados as $r) {
        $linhas[] = [$r['nome'], csv_num($r['carga_horaria']), $r['dias_presente'], $r['dias_falta'], $r['dias_justificado'],
                     csv_num($r['horas_previstas']), csv_num($r['horas_trabalhadas']), csv_num($r['creditos']), csv_num($r['debitos']),
                     csv_num($r['creditos'] - $r['debitos'])];
    }
    exportar_csv('banco_horas_' . $data_inicio . '_a_' . $data_fim . '.csv',
        ['Funcionário', 'Carga diária', 'Dias presente', 'Faltas', 'Justificados', 'Horas previstas', 'Horas trabalhadas', 'Créditos (h)', 'Débitos (h)', 'Saldo (h)'], $linhas);
}

$totais = ['prev' => 0, 'trab' => 0, 'cred' => 0, 'deb' => 0];
foreach ($resultados as $r) {
    $totais['prev'] += $r['horas_previstas'];
    $totais['trab'] += $r['horas_trabalhadas'];
    $totais['cred'] += $r['creditos'];
    $totais['deb'] += $r['debitos'];
}
$saldo_total = $totais['cred'] - $totais['deb'];

// Saldo com sinal e cor
function fmt_saldo($valor) {
    $classe = $valor < 0 ? 'text-danger' : ($valor > 0 ? 'text-success' : 'text-muted');
    return '<span class="' . $classe . '">' . ($valor > 0 ? '+' : '') . fmt_horas($valor) . '</span>';
}

render_cabecalho('Banco de Horas', 'Saldo por funcionário: créditos (horas além da carga) menos débitos (horas a menos e faltas).');
?>

<?php render_filtro_periodo($empresas, $data_inicio, $data_fim, $empresa_id, [], lista_funcionarios_filtro($pdo)); ?>

<?php if ($empresa_id && $pendencias): render_pendencias($pendencias, $empresa_id, 'consultar o banco de horas', $filial_id); elseif ($empresa_id): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-calendar2-week"></i>Horas previstas</div><div class="metric-value"><?php echo fmt_horas($totais['prev']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-arrow-up-circle text-success"></i>Créditos</div><div class="metric-value text-success"><?php echo ($totais['cred'] > 0 ? '+' : '') . fmt_horas($totais['cred']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-label"><i class="bi bi-arrow-down-circle text-danger"></i>Débitos</div><div class="metric-value text-danger"><?php echo ($totais['deb'] > 0 ? '−' : '') . fmt_horas($totais['deb']); ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="metric-card is-primary"><div class="metric-label"><i class="bi bi-hourglass-split"></i>Saldo do período</div><div class="metric-value"><?php echo ($saldo_total > 0 ? '+' : '') . fmt_horas($saldo_total); ?></div></div></div>
</div>

<div class="card card-custom overflow-hidden">
    <?php render_cabecalho_relatorio('Banco de horas', nome_empresa($empresas, $empresa_id) . ($filial_id ? ' · ' . nome_filial(lista_filiais($pdo, false), $filial_id) : ''), $data_inicio, $data_fim, (funcionarios_filtrados() && $resultados ? '<i class="bi bi-person me-1"></i>' . e(nomes_funcionarios($resultados, funcionarios_filtrados())) . '<span class="mx-2">|</span>' : '') . 'Justificado conta como dia normal (carga padrão)'); ?>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Funcionário</th>
                    <th class="num">Carga</th>
                    <th class="num">Presente</th>
                    <th class="num">Faltas</th>
                    <th class="num">Justif.</th>
                    <th class="num">Previstas</th>
                    <th class="num">Trabalhadas</th>
                    <th class="num">Créditos</th>
                    <th class="num">Débitos</th>
                    <th class="num">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($resultados): foreach ($resultados as $r): ?>
                <tr>
                    <td class="fw-medium"><?php echo e($r['nome']); ?><?php echo (int)$r['status'] === 1 ? '' : ' <span class="badge-app badge-neutral ms-1">inativo</span>'; ?></td>
                    <td class="num text-muted"><?php echo fmt_horas($r['carga_horaria']); ?></td>
                    <td class="num"><?php echo (int)$r['dias_presente']; ?></td>
                    <td class="num <?php echo $r['dias_falta'] > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo (int)$r['dias_falta']; ?></td>
                    <td class="num <?php echo $r['dias_justificado'] > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo (int)$r['dias_justificado']; ?></td>
                    <td class="num"><?php echo fmt_horas($r['horas_previstas']); ?></td>
                    <td class="num"><?php echo fmt_horas($r['horas_trabalhadas']); ?></td>
                    <td class="num <?php echo $r['creditos'] > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo ($r['creditos'] > 0 ? '+' : '') . fmt_horas($r['creditos']); ?></td>
                    <td class="num <?php echo $r['debitos'] > 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo ($r['debitos'] > 0 ? '−' : '') . fmt_horas($r['debitos']); ?></td>
                    <td class="num fw-bold"><?php echo fmt_saldo($r['creditos'] - $r['debitos']); ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="table-empty"><i class="bi bi-inbox"></i>Nenhum lançamento de frequência no período.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($resultados): ?>
            <tfoot>
                <tr>
                    <td colspan="5">Totais</td>
                    <td class="num"><?php echo fmt_horas($totais['prev']); ?></td>
                    <td class="num"><?php echo fmt_horas($totais['trab']); ?></td>
                    <td class="num text-success"><?php echo ($totais['cred'] > 0 ? '+' : '') . fmt_horas($totais['cred']); ?></td>
                    <td class="num text-danger"><?php echo ($totais['deb'] > 0 ? '−' : '') . fmt_horas($totais['deb']); ?></td>
                    <td class="num"><?php echo fmt_saldo($saldo_total); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_rodape(); ?>
