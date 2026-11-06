<?php
require_once 'conexao.php';

// Excluir fatura salva: somente administrador e financeiro
$pode_excluir_fatura = in_array(usuario_atual()['perfil'] ?? '', ['admin', 'financeiro'], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_fatura') {
    if ($pode_excluir_fatura) {
        $stmt = $pdo->prepare("DELETE FROM faturas_salvas WHERE id = :id" . sql_filial('filial_id', filial_do_usuario()));
        $stmt->execute([':id' => (int)($_POST['fatura_id'] ?? 0)]);
    }
    $volta = $_GET;
    $volta['excluida'] = 1;
    header('Location: calcular_faturamento.php?' . http_build_query($volta) . '#faturas');
    exit;
}

try {
    $empresas = lista_empresas($pdo);
} catch (Exception $e) {
    $empresas = [];
}

// Histórico das pré-faturas salvas: filtro pelo período da fatura, empresa e busca por nome/código
$busca      = trim((string)($_GET['busca'] ?? ''));
$hist_ini   = $_GET['hist_inicio'] ?? '';
$hist_fim   = $_GET['hist_fim'] ?? '';
$hist_emp   = (int)($_GET['hist_empresa'] ?? 0);
$data_valida = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

$sql = "SELECT fs.*, e.razao_social, fl.nome AS filial_nome, u.nome AS usuario_nome
        FROM faturas_salvas fs
        LEFT JOIN empresas_terceirizadas e ON e.id = fs.empresa_id
        LEFT JOIN filiais fl ON fl.id = fs.filial_id
        LEFT JOIN usuarios u ON u.id = fs.usuario_id
        WHERE 1 = 1" . sql_filial('fs.filial_id', filial_do_usuario());
$params = [];
if ($busca !== '') {
    $sql .= " AND fs.codigo LIKE :busca";
    $params[':busca'] = '%' . addcslashes($busca, '%_\\') . '%';
}
if ($data_valida($hist_ini)) {
    $sql .= " AND fs.data_fim >= :hist_ini";
    $params[':hist_ini'] = $hist_ini;
}
if ($data_valida($hist_fim)) {
    $sql .= " AND fs.data_inicio <= :hist_fim";
    $params[':hist_fim'] = $hist_fim;
}
if ($hist_emp) {
    $sql .= " AND fs.empresa_id = :hist_emp";
    $params[':hist_emp'] = $hist_emp;
}
$sql .= " ORDER BY fs.criado_em DESC, fs.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$soma_faturas = array_sum(array_column($faturas, 'total_geral'));
$filtrando = $busca !== '' || $hist_ini !== '' || $hist_fim !== '' || $hist_emp;

render_cabecalho('Faturamento', 'Histórico das faturas salvas na Pré-Fatura.');
?>

<?php if (!empty($_GET['salva'])): ?>
    <?php echo alerta('success', 'Pré-fatura salva no histórico de faturas.'); ?>
<?php elseif (!empty($_GET['excluida'])): ?>
    <?php echo alerta('success', 'Fatura salva excluída.'); ?>
<?php endif; ?>

<div class="card card-custom overflow-hidden" id="faturas">
    <div class="p-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="m-0"><i class="bi bi-archive me-2"></i>Faturas salvas</h4>
            <div class="text-muted small mt-1">Pré-faturas salvas com nome/código. Os valores ficam como estavam no momento em que foram salvas.</div>
        </div>
        <span class="text-muted small"><?php echo count($faturas); ?> fatura(s) · <strong><?php echo fmt_moeda($soma_faturas); ?></strong></span>
    </div>

    <form method="GET" action="calcular_faturamento.php#faturas" class="row g-3 align-items-end p-3 px-4 border-bottom no-print">
        <div class="col-md-4 col-xl-3">
            <label class="form-label">Nome ou código</label>
            <input type="search" name="busca" class="form-control" value="<?php echo e($busca); ?>" placeholder="Buscar...">
        </div>
        <div class="col-sm-6 col-md-4 col-xl-2">
            <label class="form-label">Período de</label>
            <input type="date" name="hist_inicio" class="form-control" value="<?php echo e($hist_ini); ?>">
        </div>
        <div class="col-sm-6 col-md-4 col-xl-2">
            <label class="form-label">até</label>
            <input type="date" name="hist_fim" class="form-control" value="<?php echo e($hist_fim); ?>">
        </div>
        <div class="col-md-6 col-xl-3">
            <label class="form-label">Empresa</label>
            <select name="hist_empresa" class="form-select">
                <option value="">Todas as empresas</option>
                <?php foreach ($empresas as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>" <?php echo $hist_emp === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['razao_social']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-xl-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary-custom flex-fill"><i class="bi bi-search"></i>Filtrar</button>
            <?php if ($filtrando): ?><a href="calcular_faturamento.php#faturas" class="btn btn-light-app" title="Limpar filtros"><i class="bi bi-x-lg"></i></a><?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Nome / código</th>
                    <th>Empresa</th>
                    <th>Período</th>
                    <th class="num">Total</th>
                    <th>Salva em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($faturas): foreach ($faturas as $fat): ?>
                <tr>
                    <td class="fw-medium"><a href="fatura_salva.php?id=<?php echo (int)$fat['id']; ?>"><?php echo e($fat['codigo']); ?></a></td>
                    <td>
                        <?php echo e($fat['razao_social'] ?? '—'); ?>
                        <div class="text-muted small"><?php echo e($fat['filial_nome'] ?? 'Todas as filiais'); ?><?php echo $fat['funcionario_nome'] ? ' · ' . e($fat['funcionario_nome']) : ''; ?></div>
                    </td>
                    <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($fat['data_inicio'])); ?> a <?php echo date('d/m/Y', strtotime($fat['data_fim'])); ?></td>
                    <td class="num fw-semibold"><?php echo fmt_moeda($fat['total_geral']); ?></td>
                    <td class="text-muted small text-nowrap">
                        <?php echo date('d/m/Y H:i', strtotime($fat['criado_em'])); ?>
                        <?php if ($fat['usuario_nome']): ?><div><?php echo e($fat['usuario_nome']); ?></div><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="fatura_salva.php?id=<?php echo (int)$fat['id']; ?>" class="btn btn-light-app btn-sm"><i class="bi bi-eye"></i>Ver</a>
                        <?php if ($pode_excluir_fatura): ?>
                        <form method="POST" action="?<?php echo e(http_build_query(array_diff_key($_GET, ['salva' => 1, 'excluida' => 1]))); ?>" class="d-inline" onsubmit="return confirm('Excluir a fatura salva &quot;<?php echo e(addslashes($fat['codigo'])); ?>&quot;? Essa ação não pode ser desfeita.');">
                            <input type="hidden" name="acao" value="excluir_fatura">
                            <input type="hidden" name="fatura_id" value="<?php echo (int)$fat['id']; ?>">
                            <button type="submit" class="btn btn-light-app btn-sm text-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="table-empty"><i class="bi bi-inbox"></i><?php echo $filtrando ? 'Nenhuma fatura salva encontrada com esses filtros.' : 'Nenhuma fatura salva ainda. Salve pela Pré-Fatura.'; ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_rodape(); ?>
