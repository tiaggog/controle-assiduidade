<?php
/**
 * Controle de Presença
 *
 * Mapa do período por funcionário (um quadrinho colorido por dia), com indicadores,
 * lista de substituições e de extras.
 * Acesso: Administrador, Supervisão (só a própria filial), Gestão e Financeiro.
 *
 * Cores: Presente, Falta, Justificado, Substituído (falta coberta por diarista, não conta como falta),
 * Sem lançamento e Bloqueado por documentação (hachurado).
 * Filtros: período, filial, empresa e um ou vários funcionários.
 * O relatório só abre quando todos os dias do período têm frequência lançada (buscar_pendencias).
 * Saídas: tela, impressão/PDF e Excel (?formato=csv).
 */
require_once 'conexao.php';

$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim    = $_GET['data_fim'] ?? date('Y-m-d');
$empresa_id  = $_GET['empresa_id'] ?? null;

$empresas = lista_empresas($pdo);
$filial_id = filial_filtrada();

// Dias sem frequência lançada bloqueiam a consulta
$pendencias = buscar_pendencias($pdo, $empresa_id, $data_inicio, $data_fim, funcionarios_filtrados(), $filial_id);
$funcionarios = [];
$registros = [];      // [funcionario_id][data] => registro
$lancamentos = [];    // substituições e extras
$subs_por_dia = [];   // [substituido_id][data] => nome da diarista
$dias = [];

if ($empresa_id && $data_inicio && $data_fim && $data_inicio <= $data_fim && !$pendencias) {
    // Funcionários ativos do quadro e todos que tenham lançamento no período nesta empresa
    // (inativados ou que mudaram de tipo, empresa ou filial depois continuam com o histórico)
    $stmt = $pdo->prepare("SELECT f.id, f.nome, f.carga_horaria, f.status FROM funcionarios f
                           WHERE (f.empresa_id = :empresa_id AND f.tipo = 'Funcionario' AND f.status = 1" . sql_filial('f.filial_id', $filial_id) . ")
                              OR EXISTS (SELECT 1 FROM controle_assiduidade c WHERE c.funcionario_id = f.id AND c.empresa_id = :empresa_id2
                                         AND c.data_referencia BETWEEN :ini AND :fim" . sql_filial('c.filial_id', $filial_id) . ")
                           ORDER BY f.nome ASC");
    $stmt->execute([':empresa_id' => $empresa_id, ':empresa_id2' => $empresa_id, ':ini' => $data_inicio, ':fim' => $data_fim]);
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT c.*, CASE WHEN c.presenca = 'Justificado' THEN f.carga_horaria ELSE c.horas_trabalhadas END AS horas_trabalhadas FROM controle_assiduidade c
                           INNER JOIN funcionarios f ON f.id = c.funcionario_id
                           WHERE c.empresa_id = :empresa_id AND c.data_referencia BETWEEN :ini AND :fim" . sql_filial('c.filial_id', $filial_id));
    $stmt->execute([':empresa_id' => $empresa_id, ':ini' => $data_inicio, ':fim' => $data_fim]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $reg) {
        $registros[$reg['funcionario_id']][$reg['data_referencia']] = $reg;
    }

    $lancamentos = buscar_lancamentos_diaristas($pdo, $empresa_id, $data_inicio, $data_fim, $filial_id);
    foreach ($lancamentos as $lanc) {
        if ($lanc['substituido_id']) {
            $subs_por_dia[$lanc['substituido_id']][$lanc['data_referencia']] = $lanc['diarista'];
        }
    }

    $periodo = new DatePeriod(new DateTime($data_inicio), new DateInterval('P1D'), (new DateTime($data_fim))->modify('+1 day'));
    foreach ($periodo as $dia) {
        $dias[] = $dia->format('Y-m-d');
    }

    // Filtro por funcionário: só ele e as substituições feitas no lugar dele (extras não pertencem a nenhum funcionário)
    $funcionario_id = funcionarios_filtrados();
    if ($funcionario_id) {
        $escolhidos = array_flip($funcionario_id);
        $funcionarios = array_values(array_filter($funcionarios, fn($f) => isset($escolhidos[(int)$f['id']])));
        $registros = array_intersect_key($registros, $escolhidos);
        $lancamentos = array_values(array_filter($lancamentos, fn($l) => isset($escolhidos[(int)$l['substituido_id']])));
    }
}

// Exportação Excel: uma linha por lançamento (funcionários e diaristas)
if (($_GET['formato'] ?? '') === 'csv' && $empresa_id && !$pendencias) {
    $linhas = [];
    foreach ($funcionarios as $func) {
        foreach ($dias as $dia) {
            if (!isset($registros[$func['id']][$dia])) {
                // Sem lançamento por documentação irregular: aparece como bloqueado
                if ($dia <= date('Y-m-d') && ($prob = problemas_documentos($pdo, $func['id'], $dia))) {
                    $linhas[] = [date('d/m/Y', strtotime($dia)), $func['nome'], 'Funcionário', 'Bloqueado (sem lançamento)', '', '', '', '', 'Bloqueado: ' . implode(' · ', $prob), ''];
                }
                continue;
            }
            $reg = $registros[$func['id']][$dia];
            $linhas[] = [date('d/m/Y', strtotime($dia)), $func['nome'], 'Funcionário', isset($subs_por_dia[$func['id']][$dia]) ? 'Substituído' : ($reg['presenca'] === 'Falta' && $reg['doc_irregular'] ? 'Bloqueado' : $reg['presenca']), hora_curta($reg['hora_entrada']), hora_curta($reg['hora_saida']), csv_num($reg['horas_trabalhadas']), $subs_por_dia[$func['id']][$dia] ?? '', $reg['doc_irregular'] ? 'Bloqueado: ' . $reg['doc_irregular'] : '', $reg['observacao']];
        }
    }
    foreach ($lancamentos as $lanc) {
        $tipo = $lanc['substituido_id'] ? 'Diarista (substituição)' : 'Extra (avulso)';
        $condicao = $lanc['substituido_id'] ? 'Substituindo ' . $lanc['substituido'] : 'Extra';
        $linhas[] = [date('d/m/Y', strtotime($lanc['data_referencia'])), $lanc['diarista'], $tipo, $condicao, hora_curta($lanc['hora_entrada']), hora_curta($lanc['hora_saida']), csv_num($lanc['horas_trabalhadas']), '', '', $lanc['observacao']];
    }
    exportar_csv('controle_presenca_' . $data_inicio . '_a_' . $data_fim . '.csv',
        ['Data', 'Nome', 'Tipo', 'Condição', 'Entrada', 'Saída', 'Horas', 'Substituído por', 'Documentação', 'Observação'], $linhas);
}

$siglas = ['Presente' => 'P', 'Falta' => 'F', 'Justificado' => 'J'];
$dias_semana = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

// Indicadores do período
// (falta coberta por diarista é substituição, não falta)
$kpi = ['P' => 0, 'F' => 0, 'J' => 0, 'S' => 0];
foreach ($registros as $fid => $por_dia) {
    foreach ($por_dia as $dia_reg => $reg) {
        if ($reg['presenca'] === 'Falta' && $reg['doc_irregular'] && !isset($subs_por_dia[$fid][$dia_reg])) {
            continue; // bloqueado por documentação: aparece no card próprio
        }
        $kpi[isset($subs_por_dia[$fid][$dia_reg]) ? 'S' : $siglas[$reg['presenca']]]++;
    }
}
$total_subs = count(array_filter($lancamentos, fn($l) => $l['substituido_id']));
$total_extras = count($lancamentos) - $total_subs;
$total_lancados = array_sum($kpi);
// Bloqueado por documentação: dias lançados com a marca (substituídos) e dias sem lançamento com documentação irregular
$bloq_sem_lanc = []; // [funcionario_id][data] => motivo
foreach ($funcionarios as $func) {
    foreach ($dias as $dia) {
        if (!isset($registros[$func['id']][$dia]) && $dia <= date('Y-m-d') && ($prob = problemas_documentos($pdo, $func['id'], $dia))) {
            $bloq_sem_lanc[$func['id']][$dia] = implode(' · ', $prob);
        }
    }
}
$total_doc = array_sum(array_map('count', $bloq_sem_lanc));
foreach ($registros as $por_dia) {
    foreach ($por_dia as $reg) {
        $total_doc += $reg['doc_irregular'] ? 1 : 0;
    }
}

render_cabecalho('Controle de Presença', 'Mapa diário de presença por funcionário, com substituições de diaristas e extras do dia.');
?>
<style>
    /* Cores do mapa: cada dia é um quadrinho pintado (o detalhe aparece ao passar o mouse) */
    .mapa { --cor-P: #16a34a; --cor-F: #dc2626; --cor-J: #f59e0b; --cor-S: #6366f1; --cor-vazio: #e9edf3; }
    .mapa { border-collapse: separate; border-spacing: 0; }
    .mapa th, .mapa td { padding: 0.4rem 0.35rem !important; text-align: center; font-size: 0.78rem; min-width: 34px; }
    .mapa td.dia, .mapa thead th.dia { padding: 3px 2px !important; min-width: 28px; }
    .mapa th.nome, .mapa td.nome { text-align: left; white-space: nowrap; position: sticky; left: 0; background: #fff; z-index: 1; min-width: 200px; padding-left: 1rem !important; box-shadow: 1px 0 0 var(--app-border); }
    .mapa thead th.nome { background: var(--app-surface-2) !important; }
    .mapa thead th small { font-weight: 500; color: var(--app-subtle); }
    .mapa .tot { background: var(--app-surface-2); font-weight: 700; border-left: 1px solid var(--app-border); }
    .mapa .cel { display: block; height: 26px; border-radius: 5px; background: var(--cor-vazio); transition: transform 0.1s, box-shadow 0.1s; }
    .mapa .cel:hover { transform: scale(1.12); box-shadow: 0 2px 6px rgba(15, 27, 45, 0.25); position: relative; z-index: 2; }
    .mapa .cel-P { background: var(--cor-P); }
    .mapa .cel-F { background: var(--cor-F); }
    .mapa .cel-J { background: var(--cor-J); }
    .mapa .cel-S { background: var(--cor-S); }
    /* Dia lançado com documentação irregular: quadrinho hachurado com borda vermelha escura */
    .mapa .cel-doc { background-image: repeating-linear-gradient(45deg, rgba(0, 0, 0, 0.35) 0 3px, transparent 3px 7px); outline: 2px solid #7a271a; outline-offset: -2px; }
    .mapa .tot-P { color: var(--cor-P) !important; }
    .mapa .tot-F { color: var(--cor-F) !important; }
    .mapa .tot-J { color: #b45309 !important; }
    .mapa .tot-S { color: var(--cor-S) !important; }
    .legenda { display: flex; flex-wrap: wrap; gap: 1.25rem; font-size: 0.78rem; color: var(--app-muted); align-items: center; }
    .legenda span { display: inline-flex; align-items: center; gap: 0.45rem; }
    .legenda i { display: inline-block; width: 16px; height: 16px; border-radius: 4px; }
    .legenda .l-P { background: #16a34a; }
    .legenda .l-F { background: #dc2626; }
    .legenda .l-J { background: #f59e0b; }
    .legenda .l-S { background: #6366f1; }
    .legenda .l-vazio { background: #e9edf3; }
    .legenda .l-doc-S { background: #6366f1 repeating-linear-gradient(45deg, rgba(0, 0, 0, 0.35) 0 3px, transparent 3px 7px); outline: 2px solid #7a271a; outline-offset: -2px; }
    .legenda .l-doc { background: #9ca3af repeating-linear-gradient(45deg, rgba(0, 0, 0, 0.35) 0 3px, transparent 3px 7px); outline: 2px solid #7a271a; outline-offset: -2px; }
    /* Mantém as cores ao imprimir / salvar em PDF */
    .mapa .cel, .legenda i { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>

<?php render_filtro_periodo($empresas, $data_inicio, $data_fim, $empresa_id, [], lista_funcionarios_filtro($pdo)); ?>

<?php if ($empresa_id && $pendencias): render_pendencias($pendencias, $empresa_id, 'consultar o controle de presença', $filial_id); elseif ($empresa_id && $dias): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-check-circle text-success"></i>Presenças</div><div class="metric-value"><?php echo $kpi['P']; ?></div></div></div>
    <div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-x-circle text-danger"></i>Faltas</div><div class="metric-value"><?php echo $kpi['F']; ?></div></div></div>
    <div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-file-earmark-medical text-warning"></i>Justificadas</div><div class="metric-value"><?php echo $kpi['J']; ?></div></div></div>
    <div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-arrow-left-right"></i>Substituições</div><div class="metric-value"><?php echo $total_subs; ?></div></div></div>
    <div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-person-plus"></i>Extras</div><div class="metric-value"><?php echo $total_extras; ?></div></div></div>
    <?php if ($total_doc): ?><div class="col-6 col-lg"><div class="metric-card"><div class="metric-label"><i class="bi bi-file-earmark-lock2 text-danger"></i>Bloqueado por doc.</div><div class="metric-value text-danger"><?php echo $total_doc; ?></div></div></div><?php endif; ?>
    <div class="col-6 col-lg"><div class="metric-card is-primary"><div class="metric-label"><i class="bi bi-percent"></i>Assiduidade</div><div class="metric-value"><?php echo $total_lancados ? number_format($kpi['P'] / $total_lancados * 100, 1, ',', '.') : '0,0'; ?>%</div></div></div>
</div>

<div class="card card-custom overflow-hidden mb-4">
    <?php render_cabecalho_relatorio('Mapa de presença', nome_empresa($empresas, $empresa_id) . ($filial_id ? ' · ' . nome_filial(lista_filiais($pdo, false), $filial_id) : ''), $data_inicio, $data_fim, funcionarios_filtrados() && $funcionarios ? '<i class="bi bi-person me-1"></i>' . e(nomes_funcionarios($funcionarios, funcionarios_filtrados())) : ''); ?>
    <div class="px-4 py-2 border-bottom legenda">
        <span><i class="l-P"></i>Presente</span>
        <span><i class="l-F"></i>Falta</span>
        <span><i class="l-J"></i>Justificado</span>
        <span><i class="l-S"></i>Substituído</span>
        <span><i class="l-vazio"></i>Sem lançamento</span>
        <span><i class="l-doc"></i>Bloqueado por documentação</span>
        <span><i class="l-doc-S"></i>Bloqueado e substituído</span>
    </div>
    <div class="table-responsive">
        <table class="table mapa">
            <thead>
                <tr>
                    <th class="nome">Funcionário</th>
                    <?php foreach ($dias as $dia): $dow = (int)date('w', strtotime($dia)); ?>
                        <th class="dia"><?php echo date('d', strtotime($dia)); ?><br><small><?php echo $dias_semana[$dow]; ?></small></th>
                    <?php endforeach; ?>
                    <th class="tot"><i class="bi bi-circle-fill tot-P me-1" style="font-size:.5rem"></i>Pres.</th>
                    <th class="tot"><i class="bi bi-circle-fill tot-F me-1" style="font-size:.5rem"></i>Faltas</th>
                    <th class="tot"><i class="bi bi-circle-fill tot-J me-1" style="font-size:.5rem"></i>Just.</th>
                    <th class="tot"><i class="bi bi-circle-fill tot-S me-1" style="font-size:.5rem"></i>Subst.</th>
                    <th class="tot">Horas</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($funcionarios): ?>
                    <?php foreach ($funcionarios as $func):
                        $tot = ['P' => 0, 'F' => 0, 'J' => 0, 'S' => 0, 'H' => 0];
                    ?>
                    <tr>
                        <td class="nome fw-medium"><?php echo e($func['nome']); ?><?php echo (int)$func['status'] === 1 ? '' : ' <span class="badge-app badge-neutral ms-1">inativo</span>'; ?></td>
                        <?php foreach ($dias as $dia):
                            $reg = $registros[$func['id']][$dia] ?? null;
                            $substituta = $subs_por_dia[$func['id']][$dia] ?? null;
                            // Sábado e domingo têm jornada: são tratados como qualquer outro dia
                            $classes = '';
                            $titulo = date('d/m', strtotime($dia)) . ' — ';
                            if ($reg && $reg['presenca'] === 'Falta' && $reg['doc_irregular'] && !$substituta) {
                                // Lançado como Bloqueado (documentação irregular, sem diarista)
                                $classes .= 'cel-vazio cel-doc';
                                $titulo .= 'Bloqueado por documentação: ' . $reg['doc_irregular'];
                            } elseif ($reg) {
                                $sigla = $siglas[$reg['presenca']];
                                $classes .= 'cel-' . $sigla;
                                $tot[$sigla]++;
                                $tot['H'] += (float)$reg['horas_trabalhadas'];
                                if ($reg['doc_irregular']) {
                                    $classes .= ' cel-doc';
                                }
                                $titulo .= $reg['presenca'] . ($reg['hora_entrada'] ? ' ' . hora_curta($reg['hora_entrada']) . '–' . hora_curta($reg['hora_saida']) : '') . ' (' . fmt_horas($reg['horas_trabalhadas']) . ')' . ($reg['observacao'] ? ' — ' . $reg['observacao'] : '');
                            } else {
                                $classes .= 'cel-vazio';
                                if (isset($bloq_sem_lanc[$func['id']][$dia])) {
                                    $classes .= ' cel-doc';
                                    $titulo .= 'Bloqueado por documentação: ' . $bloq_sem_lanc[$func['id']][$dia] . ' (sem lançamento)';
                                } else {
                                    $titulo .= 'Sem lançamento';
                                }
                            }
                            if ($substituta) {
                                // Falta coberta por diarista ganha cor própria
                                // (bloqueado por documentação e substituído: azul hachurado)
                                $classes = str_replace(['cel-F', 'cel-J'], '', $classes) . ' cel-S';
                                $tot['S']++;
                                if ($reg && isset($tot[$siglas[$reg['presenca']]])) {
                                    $tot[$siglas[$reg['presenca']]]--; // substituído não conta como falta
                                }
                                $titulo = date('d/m', strtotime($dia)) . ' — Substituído por ' . $substituta;
                            }
                            if ($reg && $reg['doc_irregular'] && $substituta) {
                                $titulo .= ' | Bloqueado por documentação: ' . $reg['doc_irregular'];
                            }
                        ?>
                            <td class="dia"><span class="cel <?php echo trim($classes); ?>" title="<?php echo e($titulo); ?>" aria-label="<?php echo e($titulo); ?>"></span></td>
                        <?php endforeach; ?>
                        <td class="tot tot-P"><?php echo $tot['P']; ?></td>
                        <td class="tot tot-F"><?php echo $tot['F']; ?></td>
                        <td class="tot tot-J"><?php echo $tot['J']; ?></td>
                        <td class="tot tot-S"><?php echo $tot['S']; ?></td>
                        <td class="tot"><?php echo fmt_horas($tot['H']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?php echo count($dias) + 6; ?>" class="table-empty"><i class="bi bi-people"></i>Nenhum funcionário encontrado para esta empresa.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$substituicoes = array_values(array_filter($lancamentos, fn($l) => $l['substituido_id']));
$extras = array_values(array_filter($lancamentos, fn($l) => !$l['substituido_id']));
?>
<?php if (empty($funcionario_id)): // extras não pertencem a nenhum funcionário ?>
<div class="card card-custom overflow-hidden mb-4">
    <div class="p-3 px-4 border-bottom d-flex align-items-center justify-content-between">
        <h4 class="m-0"><i class="bi bi-person-plus me-2 text-success"></i>Extras <span class="text-muted fw-normal">· reforço avulso em dias de alta demanda</span></h4>
        <span class="badge-app badge-success"><?php echo count($extras); ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Nome</th>
                    <th>CPF</th>
                    <th class="num">Entrada</th>
                    <th class="num">Saída</th>
                    <th class="num">Horas</th>
                    <th>Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($extras): ?>
                    <?php foreach ($extras as $lanc): ?>
                    <tr>
                        <td class="text-muted"><?php echo date('d/m/Y', strtotime($lanc['data_referencia'])); ?></td>
                        <td class="fw-medium"><?php echo e($lanc['diarista']); ?></td>
                        <td class="text-muted"><?php echo e($lanc['cpf_avulso'] ?: '—'); ?></td>
                        <td class="num"><?php echo e(hora_curta($lanc['hora_entrada']) ?: '—'); ?></td>
                        <td class="num"><?php echo e(hora_curta($lanc['hora_saida']) ?: '—'); ?></td>
                        <td class="num fw-semibold"><?php echo fmt_horas($lanc['horas_trabalhadas']); ?></td>
                        <td class="text-muted"><?php echo e($lanc['observacao']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="table-empty"><i class="bi bi-inbox"></i>Nenhum extra no período.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card card-custom overflow-hidden">
    <div class="p-3 px-4 border-bottom d-flex align-items-center justify-content-between">
        <h4 class="m-0"><i class="bi bi-arrow-left-right me-2" style="color: var(--cor-S, #6366f1)"></i>Substituições <span class="text-muted fw-normal">· diaristas cobrindo faltas do quadro</span></h4>
        <span class="badge-app badge-primary"><?php echo count($substituicoes); ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Diarista</th>
                    <th>Funcionário substituído</th>
                    <th class="num">Entrada</th>
                    <th class="num">Saída</th>
                    <th class="num">Horas</th>
                    <th>Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($substituicoes): ?>
                    <?php foreach ($substituicoes as $lanc): ?>
                    <tr>
                        <td class="text-muted"><?php echo date('d/m/Y', strtotime($lanc['data_referencia'])); ?></td>
                        <td class="fw-medium"><?php echo e($lanc['diarista']); ?></td>
                        <td><?php echo e($lanc['substituido']); ?></td>
                        <td class="num"><?php echo e(hora_curta($lanc['hora_entrada']) ?: '—'); ?></td>
                        <td class="num"><?php echo e(hora_curta($lanc['hora_saida']) ?: '—'); ?></td>
                        <td class="num fw-semibold"><?php echo fmt_horas($lanc['horas_trabalhadas']); ?></td>
                        <td class="text-muted"><?php echo e($lanc['observacao']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="table-empty"><i class="bi bi-inbox"></i>Nenhuma substituição no período.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_rodape(); ?>
