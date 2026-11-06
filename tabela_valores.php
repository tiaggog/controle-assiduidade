<?php
/**
 * Tabela de Valores
 *
 * Valor MENSAL de cada categoria por filial e turno (T1 = diurno, T3 = noturno):
 *  - Fixo: Líder, Conferente e Ajudante/Substituto (quadro e substituições pelo cargo da diarista);
 *  - Diarista Extra: Semana e Domingos/Feriados (extras; Domingos/Feriados também vale para fixo).
 * A pré-fatura divide o valor mensal pelos dias úteis informados para chegar ao valor do dia.
 * Acesso: Administrador e Financeiro editam; Gestão só visualiza.
 * Ação (POST salvar_valores): salva a grade inteira (aceita valores como 3.145,00).
 */
require_once 'conexao.php';

// Valores mensais por filial (CD), categoria e turno. Editar: administrador e financeiro; gestão só consulta
$pode_editar = in_array(usuario_atual()['perfil'] ?? '', ['admin', 'financeiro'], true);
$filiais = lista_filiais($pdo);
$categorias = categorias_valor();
$turnos = array_keys(turnos());

// Aceita "3.145,00", "3145,00" ou "3145.00"
function ler_valor_moeda($texto) {
    $texto = trim((string)$texto);
    if ($texto === '') {
        return 0.0;
    }
    if (strpos($texto, ',') !== false) {
        $texto = str_replace(['.', ','], ['', '.'], $texto);
    }
    return is_numeric($texto) ? max(0.0, round((float)$texto, 2)) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_valores'])) {
    if (!$pode_editar) {
        $_SESSION['msg_valores'] = alerta('danger', 'Seu perfil só pode consultar a tabela de valores.');
    } else {
        $ids_filiais = array_column($filiais, 'id');
        $invalidos = 0;
        $stmt = $pdo->prepare("INSERT INTO valores_categoria (filial_id, categoria, turno, valor) VALUES (:filial_id, :categoria, :turno, :valor)
                               ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $pdo->beginTransaction();
        foreach ($_POST['valor'] ?? [] as $filial_id => $por_categoria) {
            if (!in_array((int)$filial_id, array_map('intval', $ids_filiais), true)) {
                continue;
            }
            foreach ((array)$por_categoria as $categoria => $por_turno) {
                if (!isset($categorias[$categoria])) {
                    continue;
                }
                foreach ((array)$por_turno as $turno => $texto) {
                    if (!in_array($turno, $turnos, true)) {
                        continue;
                    }
                    $valor = ler_valor_moeda($texto);
                    if ($valor === null) {
                        $invalidos++;
                        continue;
                    }
                    $stmt->execute([':filial_id' => (int)$filial_id, ':categoria' => $categoria, ':turno' => $turno, ':valor' => $valor]);
                }
            }
        }
        $pdo->commit();
        $_SESSION['msg_valores'] = $invalidos
            ? alerta('warning', "Tabela salva, mas $invalidos valor(es) inválido(s) foram ignorados.")
            : alerta('success', 'Tabela de valores salva.');
    }
    header('Location: tabela_valores.php');
    exit;
}

$mensagem = $_SESSION['msg_valores'] ?? '';
unset($_SESSION['msg_valores']);
$tabela = tabela_valores($pdo);
$grupos = [];
foreach ($categorias as $chave => [$grupo]) {
    $grupos[$grupo] = ($grupos[$grupo] ?? 0) + count($turnos);
}

render_cabecalho('Tabela de Valores', 'Valor mensal de cada categoria por filial e turno. A pré-fatura cobra: valor ÷ dias úteis × dias trabalhados.');
?>

<?php echo $mensagem; ?>

<form method="POST" class="card card-custom overflow-hidden">
    <input type="hidden" name="salvar_valores" value="1">
    <div class="p-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted small">
            <strong>T1</strong> = turno diurno · <strong>T3</strong> = turno noturno.
            Substituição usa a coluna do <strong>cargo da diarista</strong>. Domingo ou feriado trabalhado por fixo ou por diarista extra usa <strong>Domingos/Feriados</strong> (substituição não).
        </div>
        <?php if ($pode_editar): ?>
        <button type="submit" class="btn btn-primary-custom"><i class="bi bi-check2"></i>Salvar tabela</button>
        <?php else: ?>
        <span class="badge-app badge-neutral"><i class="bi bi-eye me-1"></i>Somente consulta</span>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered mb-0 tabela-valores">
            <thead>
                <tr>
                    <th rowspan="3" class="align-middle">Filial (CD)</th>
                    <?php foreach ($grupos as $grupo => $span): ?>
                        <th colspan="<?php echo $span; ?>" class="text-center"><?php echo e($grupo); ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($categorias as [, $rotulo]): ?>
                        <th colspan="<?php echo count($turnos); ?>" class="text-center"><?php echo e($rotulo); ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($categorias as $c): foreach ($turnos as $t): ?>
                        <th class="text-center" title="<?php echo $t === 'T1' ? 'Diurno' : 'Noturno'; ?>"><?php echo $t; ?></th>
                    <?php endforeach; endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($filiais): foreach ($filiais as $fil): ?>
                <tr>
                    <td class="fw-semibold text-nowrap"><?php echo e($fil['nome']); ?></td>
                    <?php foreach ($categorias as $categoria => $c): foreach ($turnos as $t):
                        $valor = $tabela[(int)$fil['id']][$categoria][$t] ?? 0; ?>
                        <td>
                            <input type="text" inputmode="decimal" class="form-control form-control-sm text-end campo-valor<?php echo $valor > 0 ? '' : ' vazio'; ?>"
                                   name="valor[<?php echo (int)$fil['id']; ?>][<?php echo $categoria; ?>][<?php echo $t; ?>]"
                                   value="<?php echo $valor > 0 ? number_format($valor, 2, ',', '.') : ''; ?>" placeholder="0,00"
                                   aria-label="<?php echo e($fil['nome'] . ' ' . $c[1] . ' ' . $t); ?>" <?php echo $pode_editar ? '' : 'disabled'; ?>>
                        </td>
                    <?php endforeach; endforeach; ?>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="<?php echo 1 + count($categorias) * count($turnos); ?>" class="table-empty"><i class="bi bi-buildings"></i>Nenhuma filial ativa. Cadastre em <a href="cadastro_filial.php">Filiais</a>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="p-3 px-4 border-top small text-muted">
        <i class="bi bi-info-circle me-1"></i>Exemplo: Líder T1 = R$ 3.145,00 e 22 dias úteis → cada dia trabalhado vale R$ 142,95. Para ver uma nova filial aqui (ex.: Juazeiro), cadastre-a em <a href="cadastro_filial.php">Filiais</a>.
    </div>
</form>

<style>
    .tabela-valores th { font-size: .75rem; white-space: nowrap; vertical-align: middle; }
    /* Cabeçalhos da tabela de valores em preto, com destaque (sem negrito) */
    .table.tabela-valores thead th { color: #101828 !important; font-weight: 500; }
    .tabela-valores td { padding: .35rem; }
    .tabela-valores .campo-valor { min-width: 105px; font-variant-numeric: tabular-nums; }
    .tabela-valores .campo-valor.vazio:not(:focus) { background: var(--app-warning-soft, #fffaeb); }
</style>

<?php render_rodape(); ?>
