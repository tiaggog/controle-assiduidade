<?php
/**
 * Lançar Frequência
 *
 * Folha diária por data, filial e empresa. Acesso: Administrador e Supervisão (só a própria filial).
 *
 * Condições de cada funcionário do quadro:
 *  - Presente (entrada e saída obrigatórias) e Domingo/Feriado (presença em dia especial);
 *  - Falta, Justificado (conta como dia normal) e Substituído (falta coberta por uma diarista);
 *  - Bloqueado: documentação irregular no dia; nesse caso só Bloqueado ou Substituído são aceitos.
 * Extras do dia: diaristas chamadas por aumento de demanda.
 *
 * Regras principais:
 *  - A folha só é salva completa (todos os funcionários e horários preenchidos);
 *  - Depois de salvo, o dia só pode ser alterado pelo Administrador (os demais completam quem faltou);
 *  - Cargo, turno e a marca de documentação irregular ficam gravados no lançamento (histórico);
 *  - Substituições e extras usam diaristas cadastradas e com documentação em dia.
 */
require_once 'conexao.php';

// Captura os parâmetros via GET (ajustado para bater com o resto do escopo)
$empresa_selecionado = $_GET['empresa_id'] ?? null;
$data_selecionada = $_GET['data_referencia'] ?? date('Y-m-d');
// A folha é por filial: a supervisão usa a dela; o admin escolhe
$filial_selecionada = filial_do_usuario() ?? (int)($_GET['filial_id'] ?? 0);
$filiais = lista_filiais($pdo);
$funcionarios = [];
$diaristas = [];
$substituicoes = [];
$extras = [];
$mensagem = '';
$dia_travado = false;
$diaristas_bloqueadas = 0;

// Funcionários da empresa com frequência já gravada no dia: [id => true]
function funcionarios_lancados(PDO $pdo, $empresa_id, $filial_id, $data) {
    $stmt = $pdo->prepare("SELECT c.funcionario_id FROM controle_assiduidade c
                           WHERE c.empresa_id = :empresa_id AND c.filial_id = :filial_id AND c.data_referencia = :data_ref");
    $stmt->execute([':empresa_id' => $empresa_id, ':filial_id' => $filial_id, ':data_ref' => $data]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

// Confere se a folha está completa. Retorna a lista do que falta preencher (vazia = pode salvar)
function validar_folha(PDO $pdo, $empresa_id, $filial_id, array $frequencias, array $subs, array $extras, array $ignorar) {
    $stmt = $pdo->prepare("SELECT id, nome FROM funcionarios WHERE empresa_id = :empresa_id AND filial_id = :filial_id AND tipo = 'Funcionario' AND status = 1 ORDER BY nome");
    $stmt->execute([':empresa_id' => $empresa_id, ':filial_id' => $filial_id]);
    $faltando = [];
    $vazio = fn($v) => trim((string)($v ?? '')) === '';

    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $nome) {
        if (isset($ignorar[$id])) {
            continue;
        }
        $dados = $frequencias[$id] ?? [];
        $condicao = $dados['presenca'] ?? '';
        if (!in_array($condicao, ['Presente', 'DomFeriado', 'Falta', 'Justificado', 'Substituido', 'Bloqueado'], true)) {
            $faltando[] = "$nome: condição";
            continue;
        }
        if (in_array($condicao, ['Presente', 'DomFeriado'], true) && ($vazio($dados['entrada'] ?? null) || $vazio($dados['saida'] ?? null))) {
            $faltando[] = "$nome: entrada e saída";
        }
        if ($condicao === 'Substituido') {
            $sub = $subs[$id] ?? [];
            if (empty($sub['diarista_id'])) {
                $faltando[] = "$nome: diarista que substituiu";
            }
            if ($vazio($sub['entrada'] ?? null) || $vazio($sub['saida'] ?? null)) {
                $faltando[] = "$nome: entrada e saída da diarista";
            }
        }
    }

    foreach (array_values($extras) as $i => $ext) {
        $rotulo = 'Extra ' . ($i + 1) . ($vazio($ext['nome'] ?? null) ? '' : ' (' . trim($ext['nome']) . ')');
        // Extra é uma diarista da lista (lançamentos antigos podem ter só o nome digitado)
        if (empty($ext['diarista_id']) && $vazio($ext['nome'] ?? null)) {
            $faltando[] = "$rotulo: diarista";
        }
        if ($vazio($ext['entrada'] ?? null) || $vazio($ext['saida'] ?? null)) {
            $faltando[] = "$rotulo: entrada e saída";
        }
    }
    return $faltando;
}

// Normaliza os dados de horário/horas vindos do formulário
function ler_horario(array $dados, $carga_padrao) {
    $entrada = !empty($dados['entrada']) ? $dados['entrada'] : null;
    $saida = !empty($dados['saida']) ? $dados['saida'] : null;
    // Horas não são digitadas: saem de entrada/saída, ou da carga padrão quando não há horário
    $horas = calcular_horas($entrada, $saida) ?? (float)$carga_padrao;
    $obs = trim($dados['observacao'] ?? '');
    return [$entrada, $saida, min(max($horas, 0), 24), $obs !== '' ? $obs : null];
}

// Processa o lote de lançamentos enviado pelo gestor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_frequencia'])) {
    $data_ponto = $_POST['data_referencia'] ?? null;
    $empresa_ponto = (int)($_POST['empresa_id'] ?? 0);
    $frequencias = $_POST['ponto'] ?? []; // Array contendo id_funcionario => [presenca, entrada, saida, horas, obs]
    $subs_post = $_POST['substituicao'] ?? []; // id_funcionario_substituido => [diarista_id, entrada, saida, horas, obs]
    $extras_post = $_POST['extras'] ?? []; // lista de [nome, cpf, entrada, saida, horas, obs] de pessoas avulsas
    $filial_ponto = filial_do_usuario() ?? (int)($_POST['filial_id'] ?? 0);
    if ($filial_ponto <= 0) {
        $empresa_ponto = 0;
        $mensagem = alerta('danger', 'Escolha a filial da folha antes de salvar.');
    }

    // Só entram funcionários ativos desta empresa e filial (ou já lançados nela)
    $stmt_quadro = $pdo->prepare("SELECT id FROM funcionarios WHERE empresa_id = :empresa_id AND filial_id = :filial_id AND tipo = 'Funcionario' AND status = 1");
    $stmt_quadro->execute([':empresa_id' => $empresa_ponto, ':filial_id' => $filial_ponto]);
    $quadro_filial = array_fill_keys($stmt_quadro->fetchAll(PDO::FETCH_COLUMN), true);

    // Frequência já lançada só pode ser alterada pelo admin: os demais perfis apenas completam quem ficou sem lançamento
    $ja_lancados = funcionarios_lancados($pdo, $empresa_ponto, $filial_ponto, $data_ponto);
    $frequencias = array_intersect_key($frequencias, $quadro_filial + $ja_lancados);
    $subs_post = array_intersect_key($subs_post, $quadro_filial + $ja_lancados);
    $dia_bloqueado = !usuario_admin() && $ja_lancados;
    if ($dia_bloqueado) {
        $frequencias = array_diff_key($frequencias, $ja_lancados);
        $subs_post = array_diff_key($subs_post, $ja_lancados);
        $extras_post = [];
        if (!$frequencias) {
            $mensagem = alerta('danger', 'Não é mais possível editar: a frequência deste dia já foi lançada.');
        }
    }

    // Funcionário com documentação irregular no dia só pode ficar "Bloqueado" (falta sem cobertura) ou "Substituído".
    // Ele também precisa ser lançado: o dia só fica completo com todos os funcionários lançados.
    if ($data_ponto && $empresa_ponto) {
        $stmt_doc_lanc = $pdo->prepare("SELECT doc_irregular FROM controle_assiduidade WHERE funcionario_id = :id AND data_referencia = :data");
        foreach ($frequencias as $id_freq => $dados_freq) {
            $stmt_doc_lanc->execute([':id' => $id_freq, ':data' => $data_ponto]);
            $doc_bloqueado = isset($ja_lancados[$id_freq]) ? (bool)$stmt_doc_lanc->fetchColumn() : (bool)problemas_documentos($pdo, $id_freq, $data_ponto);
            $condicao_freq = $dados_freq['presenca'] ?? '';
            if ($doc_bloqueado && !in_array($condicao_freq, ['Bloqueado', 'Substituido'], true)) {
                $frequencias[$id_freq]['presenca'] = 'Bloqueado';
            } elseif (!$doc_bloqueado && $condicao_freq === 'Bloqueado') {
                $frequencias[$id_freq]['presenca'] = ''; // só quem está com documentação irregular pode ficar bloqueado
            }
        }
    }

    // A folha só é salva completa: todos os funcionários e horários preenchidos (motivo é opcional)
    $pendencias_folha = ($data_ponto && $empresa_ponto && $frequencias)
        ? validar_folha($pdo, $empresa_ponto, $filial_ponto, $frequencias, $subs_post, $extras_post, $dia_bloqueado ? $ja_lancados : [])
        : [];
    if ($pendencias_folha) {
        $mensagem = alerta('danger', 'Não foi possível salvar. Preencha: <ul class="mb-0 mt-1">' . implode('', array_map(fn($p) => '<li>' . e($p) . '</li>', $pendencias_folha)) . '</ul>');
        $frequencias = [];
    }

    if ($data_ponto && $empresa_ponto && !empty($frequencias)) {
        try {
            $pdo->beginTransaction();

            // Query adaptada para fazer INSERT ou UPDATE se o registro do funcionário no dia já existir
            // Empresa e filial ficam gravadas no lançamento: o histórico não muda se o cadastro mudar depois
            // Cargo e turno também ficam gravados (base do valor na pré-fatura)
            $sql_salvar = "INSERT INTO controle_assiduidade (funcionario_id, empresa_id, filial_id, cargo, turno, data_referencia, presenca, dia_especial, doc_irregular, hora_entrada, hora_saida, horas_trabalhadas, observacao)
                           VALUES (:funcionario_id, :empresa_id, :filial_id, :cargo, :turno, :data_ref, :presenca, :dia_especial, :doc_irregular, :entrada, :saida, :horas, :obs)
                           ON DUPLICATE KEY UPDATE
                           empresa_id = VALUES(empresa_id),
                           filial_id = VALUES(filial_id),
                           cargo = VALUES(cargo),
                           turno = VALUES(turno),
                           presenca = VALUES(presenca),
                           dia_especial = VALUES(dia_especial),
                           hora_entrada = VALUES(hora_entrada),
                           hora_saida = VALUES(hora_saida),
                           horas_trabalhadas = VALUES(horas_trabalhadas),
                           observacao = VALUES(observacao)";

            $stmt_salvar = $pdo->prepare($sql_salvar);
            $stmt_carga = $pdo->prepare("SELECT id, nome, carga_horaria, filial_id, cargo, turno FROM funcionarios WHERE id = :id");

            $substituidos = [];
            foreach ($frequencias as $func_id => $dados) {
                $presenca = in_array($dados['presenca'] ?? '', ['Presente', 'DomFeriado', 'Falta', 'Justificado', 'Substituido', 'Bloqueado'], true) ? $dados['presenca'] : 'Falta';
                // "Substituído" é uma falta coberta por diarista: grava como Falta e exige a substituição
                if ($presenca === 'Substituido') {
                    if (empty($subs_post[$func_id]['diarista_id'])) {
                        throw new Exception('Escolha a diarista para todo funcionário marcado como Substituído.');
                    }
                    $substituidos[$func_id] = true;
                    $presenca = 'Falta';
                }
                // "Bloqueado" (documentação irregular, sem diarista): grava como Falta, com a marca da documentação
                if ($presenca === 'Bloqueado') {
                    $presenca = 'Falta';
                }
                // "Domingo/Feriado" é presença em dia especial: grava Presente com a marca do dia
                $dia_especial = $presenca === 'DomFeriado' ? 1 : 0;
                if ($dia_especial) {
                    $presenca = 'Presente';
                }
                $stmt_carga->execute([':id' => $func_id]);
                $dados_func = $stmt_carga->fetch(PDO::FETCH_ASSOC) ?: [];
                $carga = (float)($dados_func['carga_horaria'] ?? 8);
                [$entrada, $saida, $horas, $obs] = ler_horario($dados, $carga);

                // Força zero horas e sem horário se o colaborador faltou
                if ($presenca === 'Falta') {
                    $entrada = $saida = null;
                    $horas = 0.00;
                }
                // Justificado é pago normalmente: vale a carga padrão do funcionário
                if ($presenca === 'Justificado') {
                    $entrada = $saida = null;
                    $horas = $carga;
                }

                $stmt_salvar->execute([
                    ':funcionario_id' => $func_id,
                    ':empresa_id'     => $empresa_ponto,
                    ':filial_id'      => $filial_ponto,
                    ':cargo'          => $dados_func['cargo'] ?? null,
                    ':turno'          => $dados_func['turno'] ?? null,
                    ':data_ref'       => $data_ponto,
                    ':presenca'       => $presenca,
                    ':dia_especial'   => $dia_especial,
                    // Documentação irregular no dia (só pode ter sido Substituído): fica marcado para os relatórios.
                    // Na regravação a marca do primeiro lançamento é mantida (não entra no UPDATE).
                    ':doc_irregular'  => mb_substr(implode(' · ', problemas_documentos($pdo, $func_id, $data_ponto)), 0, 255) ?: null,
                    ':entrada'        => $entrada,
                    ':saida'          => $saida,
                    ':horas'          => $horas,
                    ':obs'            => $obs
                ]);
            }

            // Diaristas já lançadas no dia continuam aceitas mesmo que a documentação tenha vencido depois
            $stmt_ja_diaristas = $pdo->prepare("SELECT diarista_id FROM lancamentos_diaristas WHERE empresa_id = :empresa_id AND filial_id = :filial_id AND data_referencia = :data_ref AND diarista_id IS NOT NULL");
            $stmt_ja_diaristas->execute([':empresa_id' => $empresa_ponto, ':filial_id' => $filial_ponto, ':data_ref' => $data_ponto]);
            $diaristas_ja_lancadas = array_fill_keys($stmt_ja_diaristas->fetchAll(PDO::FETCH_COLUMN), true);

            // Substituições e extras do dia são regravados por completo (no dia bloqueado, os já lançados são mantidos)
            $usados = [];
            if ($dia_bloqueado) {
                $stmt_usados = $pdo->prepare("SELECT diarista_id FROM lancamentos_diaristas WHERE empresa_id = :empresa_id AND filial_id = :filial_id AND data_referencia = :data_ref AND diarista_id IS NOT NULL");
                $stmt_usados->execute([':empresa_id' => $empresa_ponto, ':filial_id' => $filial_ponto, ':data_ref' => $data_ponto]);
                $usados = array_fill_keys($stmt_usados->fetchAll(PDO::FETCH_COLUMN), true);
            } else {
                $pdo->prepare("DELETE FROM lancamentos_diaristas WHERE empresa_id = :empresa_id AND filial_id = :filial_id AND data_referencia = :data_ref")
                    ->execute([':empresa_id' => $empresa_ponto, ':filial_id' => $filial_ponto, ':data_ref' => $data_ponto]);
            }

            $stmt_diarista = $pdo->prepare("INSERT INTO lancamentos_diaristas (empresa_id, filial_id, cargo, turno, data_referencia, diarista_id, substituido_id, nome_avulso, cpf_avulso, hora_entrada, hora_saida, horas_trabalhadas, observacao)
                                            VALUES (:empresa_id, :filial_id, :cargo, :turno, :data_ref, :diarista_id, :substituido_id, :nome, :cpf, :entrada, :saida, :horas, :obs)");

            // Substituições: diarista cadastrada cobrindo um funcionário do quadro
            foreach ($subs_post as $substituido_id => $dados) {
                $diarista_id = (int)($dados['diarista_id'] ?? 0);
                if (!$diarista_id || !isset($substituidos[$substituido_id])) {
                    continue;
                }
                if (isset($usados[$diarista_id])) {
                    throw new Exception('A mesma diarista foi lançada em mais de uma substituição no dia.');
                }
                $usados[$diarista_id] = true;

                $stmt_carga->execute([':id' => $diarista_id]);
                $dados_diarista = $stmt_carga->fetch(PDO::FETCH_ASSOC);
                if (!isset($diaristas_ja_lancadas[$diarista_id]) && (int)($dados_diarista['filial_id'] ?? 0) !== $filial_ponto) {
                    throw new Exception(($dados_diarista['nome'] ?? 'A diarista') . ' é de outra filial.');
                }
                if (!isset($diaristas_ja_lancadas[$diarista_id]) && problemas_documentos($pdo, $diarista_id, $data_ponto)) {
                    throw new Exception(($dados_diarista['nome'] ?? 'A diarista') . ' está com documentação irregular e não pode ser lançada.');
                }
                $carga = (float)($dados_diarista['carga_horaria'] ?? 8);
                [$entrada, $saida, $horas, $obs] = ler_horario($dados, $carga);

                $stmt_diarista->execute([
                    ':empresa_id'     => $empresa_ponto,
                    ':filial_id'      => $filial_ponto,
                    ':data_ref'       => $data_ponto,
                    ':cargo'          => $dados_diarista['cargo'] ?? null,
                    ':turno'          => $dados_diarista['turno'] ?? null,
                    ':diarista_id'    => $diarista_id,
                    ':substituido_id' => (int)$substituido_id,
                    ':nome'           => null,
                    ':cpf'            => null,
                    ':entrada'        => $entrada,
                    ':saida'          => $saida,
                    ':horas'          => $horas,
                    ':obs'            => $obs
                ]);
            }

            // Extras: diaristas da lista chamadas no dia por aumento de demanda
            foreach ($extras_post as $dados) {
                $diarista_id = (int)($dados['diarista_id'] ?? 0);
                $nome = trim($dados['nome'] ?? '');
                $cpf = trim($dados['cpf'] ?? '');
                $carga = CARGA_PADRAO_EXTRA;
                $dados_diarista = [];
                if ($diarista_id) {
                    if (isset($usados[$diarista_id])) {
                        throw new Exception('A mesma diarista foi lançada mais de uma vez no dia (substituição ou extra).');
                    }
                    $usados[$diarista_id] = true;
                    $stmt_carga->execute([':id' => $diarista_id]);
                    $dados_diarista = $stmt_carga->fetch(PDO::FETCH_ASSOC);
                    if (!$dados_diarista) {
                        throw new Exception('Diarista do extra não encontrada.');
                    }
                    if (!isset($diaristas_ja_lancadas[$diarista_id]) && (int)$dados_diarista['filial_id'] !== $filial_ponto) {
                        throw new Exception($dados_diarista['nome'] . ' é de outra filial.');
                    }
                    if (!isset($diaristas_ja_lancadas[$diarista_id]) && problemas_documentos($pdo, $diarista_id, $data_ponto)) {
                        throw new Exception($dados_diarista['nome'] . ' está com documentação irregular e não pode ser lançada.');
                    }
                    $carga = (float)$dados_diarista['carga_horaria'];
                    $nome = $cpf = '';
                } elseif ($nome === '') {
                    continue;
                }
                [$entrada, $saida, $horas, $obs] = ler_horario($dados, $carga);

                $stmt_diarista->execute([
                    ':empresa_id'     => $empresa_ponto,
                    ':filial_id'      => $filial_ponto,
                    ':data_ref'       => $data_ponto,
                    ':cargo'          => $dados_diarista['cargo'] ?? null,
                    ':turno'          => $dados_diarista['turno'] ?? null,
                    ':diarista_id'    => $diarista_id ?: null,
                    ':substituido_id' => null,
                    ':nome'           => $nome !== '' ? mb_substr($nome, 0, 100) : null,
                    ':cpf'            => $cpf !== '' ? mb_substr($cpf, 0, 14) : null,
                    ':entrada'        => $entrada,
                    ':saida'          => $saida,
                    ':horas'          => $horas,
                    ':obs'            => $obs
                ]);
            }

            $pdo->commit();
            $mensagem = alerta('success', 'Folha de presença consolidada e salva com sucesso!');
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = alerta('danger', 'Falha no processamento: ' . e($e->getMessage()));
        }
    }
}

// Carrega as empresas parceiras para o filtro de busca
try {
    $empresas = lista_empresas($pdo);

    // Se escolheu uma empresa, busca os funcionários dela trazendo dados de ponto existentes (caso o gestor já tenha lançado)
    if ($empresa_selecionado && $filial_selecionada > 0) {
        // Abrir a folha do dia conta como notificação aberta no sininho
        marcar_notificacao_vista($pdo, $empresa_selecionado, $filial_selecionada, $data_selecionada);

        $sql_func = "SELECT f.id, f.nome, f.carga_horaria, c.presenca, c.dia_especial, c.doc_irregular, c.hora_entrada, c.hora_saida, c.horas_trabalhadas, c.observacao
                     FROM funcionarios f
                     LEFT JOIN controle_assiduidade c ON f.id = c.funcionario_id AND c.data_referencia = :data_ref
                     WHERE f.empresa_id = :empresa_id AND f.filial_id = :filial_id AND f.status = 1 AND f.tipo = 'Funcionario'
                     ORDER BY f.nome ASC";
        $stmt_func = $pdo->prepare($sql_func);
        $stmt_func->execute([':empresa_id' => $empresa_selecionado, ':filial_id' => $filial_selecionada, ':data_ref' => $data_selecionada]);
        $funcionarios = $stmt_func->fetchAll(PDO::FETCH_ASSOC);
        // Documentação irregular no dia: aparece bloqueado na folha, só com a opção Substituído (já lançado: a marca gravada)
        foreach ($funcionarios as &$func_doc) {
            $func_doc['problemas_doc'] = $func_doc['presenca'] === null
                ? problemas_documentos($pdo, $func_doc['id'], $data_selecionada)
                : ($func_doc['doc_irregular'] ? [$func_doc['doc_irregular']] : []);
        }
        unset($func_doc);

        // Dia já lançado fica somente leitura para quem não é admin
        $dia_travado = !usuario_admin() && array_filter($funcionarios, fn($f) => $f['presenca'] !== null);

        // Diaristas ativas da filial, da empresa ou avulsas (e as que já estão lançadas na folha, mesmo se inativadas depois)
        $sql_diar = "SELECT id, nome, carga_horaria, status FROM funcionarios
                     WHERE tipo = 'Diarista'
                       AND ((status = 1 AND filial_id = :filial_id AND (empresa_id = :empresa_id OR empresa_id IS NULL))
                            OR id IN (SELECT diarista_id FROM lancamentos_diaristas WHERE empresa_id = :empresa_id2 AND filial_id = :filial_id2 AND data_referencia = :data_ref))
                     ORDER BY nome ASC";
        $stmt_diar = $pdo->prepare($sql_diar);
        $stmt_diar->execute([':empresa_id' => $empresa_selecionado, ':empresa_id2' => $empresa_selecionado, ':filial_id' => $filial_selecionada, ':filial_id2' => $filial_selecionada, ':data_ref' => $data_selecionada]);
        $diaristas = $stmt_diar->fetchAll(PDO::FETCH_ASSOC);

        foreach (buscar_lancamentos_diaristas($pdo, $empresa_selecionado, $data_selecionada, $data_selecionada, $filial_selecionada) as $lanc) {
            if ($lanc['substituido_id']) {
                $substituicoes[$lanc['substituido_id']] = $lanc;
            } else {
                $extras[] = $lanc;
            }
        }

        // Diarista com documentação irregular não aparece para escolha (a já lançada no dia continua)
        $diaristas_no_dia = array_column(array_merge(array_values($substituicoes), $extras), 'diarista_id', 'diarista_id');
        $total_diaristas = count($diaristas);
        $diaristas = array_values(array_filter($diaristas, fn($d) => isset($diaristas_no_dia[$d['id']]) || !problemas_documentos($pdo, $d['id'], $data_selecionada)));
        $diaristas_bloqueadas = $total_diaristas - count($diaristas);

    }
} catch (Exception $e) {
    $empresas = [];
}

// Monta o <select> de diaristas
function opcoes_diaristas(array $diaristas, $selecionada = null) {
    $html = '<option value="">Selecione a diarista...</option>';
    foreach ($diaristas as $d) {
        $sel = (string)$selecionada === (string)$d['id'] ? ' selected' : '';
        $inativa = (int)$d['status'] === 1 ? '' : ' (inativa)';
        $html .= '<option value="' . $d['id'] . '" data-carga="' . (float)$d['carga_horaria'] . '"' . $sel . '>' . e($d['nome']) . $inativa . '</option>';
    }
    return $html;
}

// Campos de horário (entrada, saída, horas) reutilizados nas linhas

// Saldo do dia (horas feitas x carga): vermelho abaixo, azul-marinho igual, verde acima
function celula_saldo($horas, $carga) {
    $diferenca = (int)round(((float)$horas - (float)$carga) * 60); // em minutos
    $classe = $diferenca < 0 ? 'saldo-abaixo' : ($diferenca > 0 ? 'saldo-acima' : 'saldo-igual');
    $texto = ($diferenca > 0 ? '+' : ($diferenca < 0 ? '−' : '')) . hhmm($diferenca / 60);
    echo '<td class="text-center"><span class="saldo-dia js-saldo ' . $classe . '">' . $texto . '</span></td>';
}

function campos_horario($prefixo, $entrada, $saida, $horas, $carga, $desabilitado = false, $com_saldo = false) {
    $dis = $desabilitado ? ' disabled' : '';
    ?>
    <td><input type="time" name="<?php echo $prefixo; ?>[entrada]" class="form-control form-control-sm js-entrada" value="<?php echo e(hora_curta($entrada)); ?>" required<?php echo $dis; ?>></td>
    <td><input type="time" name="<?php echo $prefixo; ?>[saida]" class="form-control form-control-sm js-saida" value="<?php echo e(hora_curta($saida)); ?>" required<?php echo $dis; ?>></td>
    <?php // Com saldo (quadro e substituição) a coluna mostra a carga horária; as horas feitas ficam em data-valor para o saldo ?>
    <td><input type="text" name="<?php echo $prefixo; ?>[horas]" class="form-control form-control-sm js-horas<?php echo $com_saldo ? ' js-mostra-carga' : ''; ?>" value="<?php echo hhmm($com_saldo ? $carga : $horas); ?>" data-valor="<?php echo e((float)$horas); ?>" data-carga="<?php echo e($carga); ?>" readonly tabindex="-1" title="<?php echo $com_saldo ? 'Carga horária do cadastro' : 'Calculado pela entrada e saída'; ?>"<?php echo $dis; ?>></td>
    <?php
    if ($com_saldo) {
        celula_saldo($horas, $carga);
    }
}

render_cabecalho('Lançar Frequência', 'Registro diário de presença, horários, substituições e extras por empresa.');
?>
<style>
    .linha-substituicao td { background: #fffcf5 !important; border-top: 0; }
    .linha-substituicao td:first-child { box-shadow: inset 3px 0 0 #f79009; }
    .linha-substituicao .rotulo-sub { font-size: 0.78rem; color: var(--app-warning); font-weight: 600; white-space: nowrap; }
    .linha-extra td:first-child { box-shadow: inset 3px 0 0 #12b76a; }
    .bloco-funcionario { border-bottom: 1px solid var(--app-border); }
    .bloco-funcionario:hover > tr:first-child td { background: #f6f8fc; }
    .tabela-ponto input[type=time] { min-width: 105px; }
    .tabela-ponto .js-horas { min-width: 80px; background: var(--app-surface-2, #f4f6fa); color: var(--app-text, #0f1b2d); font-weight: 600; pointer-events: none; }
    .saldo-dia { display: inline-block; min-width: 78px; text-align: center; padding: 0.3rem 0.55rem; border-radius: 6px; font-weight: 700; font-size: 0.8rem; color: #fff; font-variant-numeric: tabular-nums; }
    .saldo-abaixo { background: #d92d20; }
    .saldo-igual { background: #13294b; }
    .saldo-acima { background: #067647; }
    .tabela-ponto td, .tabela-ponto .btn { white-space: nowrap; }
    .tabela-ponto td { padding-left: 0.6rem; padding-right: 0.6rem; }
    .tabela-ponto td:first-child, .tabela-ponto th:first-child { padding-left: 1.25rem; }
    .tabela-ponto input[type=text] { min-width: 130px; }
    .tabela-ponto input[type=time] { min-width: 100px !important; }
    .tabela-ponto select { min-width: 130px; }
    .foi-validado .form-control:invalid, .foi-validado .form-select:invalid { border-color: #d92d20; background-color: #fef3f2; box-shadow: 0 0 0 2px rgba(217, 45, 32, 0.15); }
    .tabela-ponto .js-diarista { min-width: 200px; }
    .linha-bloqueada-doc td { background: #fef3f2 !important; }
    .linha-bloqueada-doc td:first-child { box-shadow: inset 3px 0 0 #d92d20; }
    .linha-bloqueada-doc .small { white-space: normal; }

    /* Celular: cada linha vira um cartão (rótulo + campo) em vez de tabela com rolagem lateral */
    @media (max-width: 767.98px) {
        .tabela-ponto thead { display: none; }
        .tabela-ponto, .tabela-ponto tbody { display: block; }
        .tabela-ponto tr.js-linha { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem 0.75rem; padding: 0.9rem 1rem; }
        .tabela-ponto tr.js-linha > td { display: block; padding: 0 !important; border: 0; background: transparent !important; box-shadow: none !important; white-space: normal; text-align: left !important; min-width: 0; }
        .tabela-ponto tr.js-linha > td::before { display: block; font-size: 0.68rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--app-text-muted, #667085); margin-bottom: 0.25rem; }
        .tabela-ponto tr.js-linha > td:first-child::before { display: none; }
        .tabela-ponto input, .tabela-ponto select, .tabela-ponto input[type=time], .tabela-ponto .js-diarista { min-width: 0 !important; width: 100%; font-size: 1rem; }
        .tabela-ponto td.table-empty { display: block; }
        .bloco-funcionario { border-bottom: 1px solid var(--app-border); }
        .bloco-funcionario:hover > tr:first-child td { background: transparent; }

        /* Quadro: nome | condição | entrada, saída | carga, saldo | observação */
        .tabela-quadro td:nth-child(1), .tabela-quadro td:nth-child(2), .tabela-quadro td:nth-child(7) { grid-column: 1 / -1; }
        .tabela-quadro td:nth-child(1) { font-size: 1rem; }
        .tabela-quadro tr:first-child td:nth-child(2)::before { content: 'Condição'; }
        .tabela-quadro .linha-substituicao td:nth-child(2)::before { content: 'Diarista'; }
        .tabela-quadro td:nth-child(3)::before { content: 'Entrada'; }
        .tabela-quadro td:nth-child(4)::before { content: 'Saída'; }
        .tabela-quadro td:nth-child(5)::before { content: 'Carga horária'; }
        .tabela-quadro td:nth-child(6)::before { content: 'Saldo'; }
        .tabela-quadro td:nth-child(7)::before { content: 'Observação'; }
        .tabela-quadro .saldo-dia { display: block; padding: 0.45rem; font-size: 0.95rem; }
        .linha-substituicao { background: #fffcf5; box-shadow: inset 3px 0 0 #f79009; border-top: 1px dashed #fedf89; }

        /* Extras: diarista | entrada, saída | horas, remover | observação */
        .linha-extra { box-shadow: inset 3px 0 0 #12b76a; border-bottom: 1px solid var(--app-border); }
        .tabela-extras td:nth-child(1), .tabela-extras td:nth-child(5) { grid-column: 1 / -1; }
        .tabela-extras td:nth-child(1)::before { display: block !important; content: 'Diarista'; }
        .tabela-extras td:nth-child(2)::before { content: 'Entrada'; }
        .tabela-extras td:nth-child(3)::before { content: 'Saída'; }
        .tabela-extras td:nth-child(4)::before { content: 'Horas'; }
        .tabela-extras td:nth-child(5)::before { content: 'Observação'; }
        .tabela-extras td:nth-child(5) { order: 1; }
        .tabela-extras tr.js-linha > td:nth-child(6) { align-self: end; text-align: right !important; }
        .btn-primary-custom { white-space: nowrap; }
        .linha-bloqueada-doc { background: #fef3f2; box-shadow: inset 3px 0 0 #d92d20; }
        .linha-bloqueada-doc td::before { display: none !important; }
    }
</style>

<div>
    <?php echo $mensagem; ?>

    <div class="card card-custom p-3 px-4 mb-4">
        
        <form method="GET" action="lancar_frequencia.php" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Data operacional</label>
                <input type="date" name="data_referencia" class="form-control" value="<?php echo e($data_selecionada); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Filial</label>
                <?php render_campo_filial($filiais, $filial_selecionada, 'filial_id', 'Selecione...', 'form-select', ' required'); ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">Empresa</label>
                <select name="empresa_id" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($empresas as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo (isset($empresa_selecionado) && $empresa_selecionado == $emp['id']) ? 'selected' : ''; ?>>
                            <?php echo e($emp['razao_social']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary-custom w-100"><i class="bi bi-folder2-open"></i>Abrir folha de ponto</button>
            </div>
        </form>
    </div>

    <?php if ($empresa_selecionado && $filial_selecionada > 0): ?>
    <form action="lancar_frequencia.php?empresa_id=<?php echo (int)$empresa_selecionado; ?>&filial_id=<?php echo (int)$filial_selecionada; ?>&data_referencia=<?php echo e($data_selecionada); ?>" method="POST">
        <input type="hidden" name="data_referencia" value="<?php echo e($data_selecionada); ?>">
        <input type="hidden" name="filial_id" value="<?php echo (int)$filial_selecionada; ?>">
        <input type="hidden" name="empresa_id" value="<?php echo (int)$empresa_selecionado; ?>">
        <input type="hidden" name="salvar_frequencia" value="1">

        <?php
        $pendentes_no_dia = count(array_filter($funcionarios, fn($f) => $f['presenca'] === null));
        $bloqueados_pendentes = count(array_filter($funcionarios, fn($f) => $f['presenca'] === null && $f['problemas_doc']));
        $bloqueados_no_dia = array_filter($funcionarios, fn($f) => $f['problemas_doc'] && $f['presenca'] === null);
        if ($dia_travado): ?>
        <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-danger-soft); color: var(--app-danger); border: 1px solid #fecdca;">
            <i class="bi bi-lock-fill"></i>
            <div>
                <strong>Não é mais possível editar.</strong> A frequência deste dia já foi lançada.
                <?php if ($pendentes_no_dia): ?>
                    Você ainda pode lançar <?php echo $pendentes_no_dia === 1 ? 'o funcionário que está' : 'os ' . $pendentes_no_dia . ' funcionários que estão'; ?> sem lançamento.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($bloqueados_no_dia): ?>
        <div class="alert-app alert d-flex gap-2 align-items-start mb-4" style="background: var(--app-danger-soft); color: var(--app-danger); border: 1px solid #fecdca;">
            <i class="bi bi-file-earmark-x-fill"></i>
            <div>
                <strong><?php echo count($bloqueados_no_dia) === 1 ? '1 funcionário bloqueado' : count($bloqueados_no_dia) . ' funcionários bloqueados'; ?> por documentação irregular.</strong>
                Eles ficam como <strong>Bloqueado</strong> (falta) enquanto houver documento pendente ou vencido, ou podem ser marcados como <strong>Substituído</strong> para lançar a diarista que cobriu o dia.
                <?php if (pode_acessar('controle_documentos.php')): ?><a href="controle_documentos.php?empresa_id=<?php echo (int)$empresa_selecionado; ?>&filial_id=<?php echo (int)$filial_selecionada; ?>" class="fw-semibold" style="color: inherit;">Regularizar documentos</a><?php else: ?>Procure o RH para regularizar.<?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card card-custom overflow-hidden mb-4">
            <div class="p-3 px-4 border-bottom">
                <h5 class="m-0">Quadro de funcionários</h5>
                <small class="text-muted">Preencha todos os funcionários: condição, entrada e saída de quem está presente. O <strong>Saldo</strong> compara as horas feitas (entrada e saída) com a carga horária: <span style="color:#d92d20;font-weight:600">vermelho</span> abaixo, <span style="color:#13294b;font-weight:600">azul</span> igual e <span style="color:#067647;font-weight:600">verde</span> acima. Marque a condição <strong>Substituído</strong> para lançar a diarista que cobriu o funcionário e <strong>Domingo/Feriado</strong> para quem trabalhou em domingo ou feriado (cobrado pela coluna Domingos/Feriados; domingo já é reconhecido automaticamente).</small>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 tabela-ponto tabela-quadro">
                    <thead>
                        <tr>
                            <th>Nome do Profissional</th>
                            <th style="width: 150px;">Condição</th>
                            <th style="width: 120px;">Entrada</th>
                            <th style="width: 120px;">Saída</th>
                            <th style="width: 110px;">Carga horária</th>
                            <th style="width: 100px;" class="text-center" title="Horas feitas comparadas com a carga horária">Saldo</th>
                            <th>Observação</th>
                        </tr>
                    </thead>
                    <?php if (count($funcionarios) > 0): ?>
                        <?php foreach ($funcionarios as $func):
                            $id = (int)$func['id'];
                            $presenca_atual = $func['presenca'] ?? ''; // dia sem lançamento: a condição precisa ser escolhida
                            if ($presenca_atual === 'Presente' && !empty($func['dia_especial'])) {
                                $presenca_atual = 'DomFeriado';
                            }
                            $carga_diaria = isset($func['carga_horaria']) ? (float)$func['carga_horaria'] : 8.00;
                            $horas_atuais = $func['horas_trabalhadas'] !== null && $presenca_atual !== 'Justificado' ? (float)$func['horas_trabalhadas'] : $carga_diaria;
                            $sub = $substituicoes[$id] ?? null;
                            if ($sub) {
                                $presenca_atual = 'Substituido';
                            } elseif ($func['problemas_doc']) {
                                $presenca_atual = 'Bloqueado';
                            }
                            $travado = $dia_travado && $func['presenca'] !== null;
                            $dis = $travado ? ' disabled' : '';
                            ?>
                        <tbody class="bloco-funcionario">
                            <?php if ($func['problemas_doc']): ?>
                            <tr class="js-linha linha-bloqueada-doc">
                                <td class="fw-medium text-muted"><?php echo e($func['nome']); ?> <i class="bi bi-file-earmark-lock2-fill small ms-1" title="Documentação irregular"></i></td>
                                <td>
                                    <select name="ponto[<?php echo $id; ?>][presenca]" class="form-select form-select-sm" onchange="mostrarSubstituicao(this.closest('tr').nextElementSibling, this.value === 'Substituido')" title="Bloqueado por documentação: só pode ser substituído"<?php echo $dis; ?>>
                                        <option value="Bloqueado" <?php echo $presenca_atual === 'Substituido' ? '' : 'selected'; ?>>Bloqueado</option>
                                        <option value="Substituido" <?php echo $presenca_atual === 'Substituido' ? 'selected' : ''; ?>>Substituído</option>
                                    </select>
                                </td>
                                <td colspan="5">
                                    <span class="badge-app badge-danger"><i class="bi bi-slash-circle me-1"></i>Bloqueado: documentação irregular</span>
                                    <span class="small text-muted ms-1"><?php echo e(implode(' · ', $func['problemas_doc'])); ?></span>
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr class="js-linha">
                                <td class="fw-medium text-dark"><?php echo e($func['nome']); ?><?php echo $travado ? ' <i class="bi bi-lock-fill text-muted small ms-1" title="Já lançado: não é mais possível editar"></i>' : ''; ?></td>
                                <td>
                                    <select name="ponto[<?php echo $id; ?>][presenca]" class="form-select form-select-sm" onchange="toggleHoras(this)" required<?php echo $dis; ?>>
                                        <option value="" <?php echo $presenca_atual === '' ? 'selected' : ''; ?> disabled>Selecione...</option>
                                        <option value="Presente" <?php echo $presenca_atual === 'Presente' ? 'selected' : ''; ?>>Presente</option>
                                        <option value="DomFeriado" <?php echo $presenca_atual === 'DomFeriado' ? 'selected' : ''; ?>>Domingo/Feriado</option>
                                        <option value="Falta" <?php echo $presenca_atual === 'Falta' ? 'selected' : ''; ?>>Falta</option>
                                        <option value="Justificado" <?php echo $presenca_atual === 'Justificado' ? 'selected' : ''; ?>>Justificado</option>
                                        <option value="Substituido" <?php echo $presenca_atual === 'Substituido' ? 'selected' : ''; ?>>Substituído</option>
                                    </select>
                                </td>
                                <?php campos_horario("ponto[$id]", $func['hora_entrada'], $func['hora_saida'], $horas_atuais, $carga_diaria, $travado || in_array($presenca_atual, ['Falta', 'Substituido', 'Justificado'], true), true); ?>
                                <td>
                                    <input type="text" name="ponto[<?php echo $id; ?>][observacao]"
                                           class="form-control form-control-sm js-obs"
                                           placeholder="Opcional..."
                                           value="<?php echo e($func['observacao'] ?? ''); ?>"<?php echo $dis; ?>>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php // Linha da diarista: só aparece (e só é enviada) com a condição "Substituído" ?>
                            <tr class="linha-substituicao js-linha" id="sub_<?php echo $id; ?>" <?php echo $sub ? '' : 'style="display:none"'; ?>>
                                <td class="ps-4"><span class="rotulo-sub"><i class="bi bi-arrow-return-right me-1"></i>Substituído por</span></td>
                                <td>
                                    <select name="substituicao[<?php echo $id; ?>][diarista_id]" class="form-select form-select-sm js-diarista"<?php echo $sub && !$travado ? ' required' : ' disabled'; ?>>
                                        <?php echo opcoes_diaristas($diaristas, $sub['diarista_id'] ?? null); ?>
                                    </select>
                                </td>
                                <?php campos_horario("substituicao[$id]", $sub['hora_entrada'] ?? null, $sub['hora_saida'] ?? null, $sub ? (float)$sub['horas_trabalhadas'] : $carga_diaria, $sub ? (float)$sub['carga_diarista'] : $carga_diaria, !$sub || $travado, true); ?>
                                <td>
                                    <input type="text" name="substituicao[<?php echo $id; ?>][observacao]" class="form-control form-control-sm js-obs-sub" placeholder="Observação da substituição..." value="<?php echo e($sub['observacao'] ?? ''); ?>"<?php echo $sub && !$travado ? '' : ' disabled'; ?>>
                                </td>
                            </tr>
                        </tbody>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tbody>
                            <tr>
                                <td colspan="7" class="table-empty"><i class="bi bi-people"></i>Nenhum funcionário ativo vinculado a esta empresa.</td>
                            </tr>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if (count($funcionarios) > 0): ?>
        <div class="card card-custom overflow-hidden mb-4">
            <div class="p-3 px-4 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="m-0">Extras do dia</h5>
                    <small class="text-muted">Diaristas chamadas no dia por aumento de demanda. Escolha a diarista na lista (cadastro em <a href="cadastro_funcionario.php?tipo=Diarista">Funcionários</a>).</small>
                </div>
                <?php if (!$dia_travado): ?>
                <button type="button" class="btn btn-primary-custom" onclick="adicionarExtra()" title="Adicionar diarista extra"><i class="bi bi-plus-lg"></i>Extra</button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 tabela-ponto tabela-extras">
                    <thead>
                        <tr>
                            <th>Diarista</th>
                            <th style="width: 120px;">Entrada</th>
                            <th style="width: 120px;">Saída</th>
                            <th style="width: 110px;">Horas</th>
                            <th>Observação</th>
                            <th style="width: 90px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lista_extras">
                        <?php foreach ($extras as $i => $ext): ?>
                        <?php $dis_extra = $dia_travado ? ' disabled' : ''; ?>
                        <tr class="linha-extra js-linha">
                            <?php if ($ext['diarista_id']): ?>
                            <td><select name="extras[<?php echo $i; ?>][diarista_id]" class="form-select form-select-sm js-diarista js-diarista-extra" required<?php echo $dis_extra; ?>><?php echo opcoes_diaristas($diaristas, $ext['diarista_id']); ?></select></td>
                            <?php else: // lançamento antigo, com o nome digitado ?>
                            <td><input type="text" class="form-control form-control-sm" value="<?php echo e($ext['diarista']); ?>" readonly tabindex="-1" title="Lançado antes da lista de diaristas"<?php echo $dis_extra; ?>><input type="hidden" name="extras[<?php echo $i; ?>][nome]" value="<?php echo e($ext['diarista']); ?>"<?php echo $dis_extra; ?>><input type="hidden" name="extras[<?php echo $i; ?>][cpf]" value="<?php echo e($ext['cpf_avulso'] ?? ''); ?>"<?php echo $dis_extra; ?>></td>
                            <?php endif; ?>
                            <?php campos_horario("extras[$i]", $ext['hora_entrada'], $ext['hora_saida'], (float)$ext['horas_trabalhadas'], (float)$ext['carga_diarista'], $dia_travado); ?>
                            <td><input type="text" name="extras[<?php echo $i; ?>][observacao]" class="form-control form-control-sm" placeholder="Motivo do extra..." value="<?php echo e($ext['observacao'] ?? ''); ?>"<?php echo $dis_extra; ?>></td>
                            <td class="text-end"><?php if (!$dia_travado): ?><button type="button" class="btn btn-sm btn-light-app text-danger" onclick="this.closest('tr').remove(); atualizarVazio();" title="Remover"><i class="bi bi-trash3 me-0"></i></button><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="extras_vazio" <?php echo $extras ? 'style="display:none"' : ''; ?>>
                            <td colspan="6" class="table-empty"><i class="bi bi-person-plus"></i>Nenhum extra lançado.<?php echo $dia_travado ? '' : ' Clique em <strong>+ Extra</strong> para adicionar.'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if (!$diaristas && !$diaristas_bloqueadas): ?>
            <div class="p-3 px-4 border-top small" style="background: var(--app-warning-soft); color: var(--app-warning);"><i class="bi bi-exclamation-triangle me-1"></i>Nenhuma diarista ativa disponível para substituições nesta empresa. Cadastre em <a href="cadastro_funcionario.php">Funcionários</a> com o tipo "Diarista".</div>
            <?php endif; ?>
            <?php if ($diaristas_bloqueadas): ?>
            <div class="p-3 px-4 border-top small" style="background: var(--app-danger-soft); color: var(--app-danger);"><i class="bi bi-file-earmark-x me-1"></i><?php echo $diaristas_bloqueadas === 1 ? '1 diarista não aparece' : $diaristas_bloqueadas . ' diaristas não aparecem'; ?> para substituição por documentação irregular.<?php if (pode_acessar('controle_documentos.php')): ?> <a href="controle_documentos.php?tipo=Diarista&situacao=irregulares&filial_id=<?php echo (int)$filial_selecionada; ?>" style="color: inherit;" class="fw-semibold">Ver documentos</a><?php endif; ?></div>
            <?php endif; ?>
        </div>

        <?php if ($dia_travado ? $pendentes_no_dia : count($funcionarios) > 0): ?>
        <div class="d-flex justify-content-end align-items-center gap-3">
            <span id="aviso_incompleto" class="fw-semibold" style="display:none; color:#d92d20;"><i class="bi bi-exclamation-octagon-fill me-1"></i>Preencha todos os campos destacados em vermelho para salvar.</span>
            <button type="submit" class="btn btn-primary-custom px-4"><i class="bi bi-check2-circle"></i><?php echo $dia_travado ? 'Salvar pendentes' : 'Salvar registro diário'; ?></button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </form>

    <template id="modelo_extra">
        <tr class="linha-extra js-linha">
            <td><select name="extras[__i__][diarista_id]" class="form-select form-select-sm js-diarista js-diarista-extra" required><?php echo opcoes_diaristas($diaristas); ?></select></td>
            <td><input type="time" name="extras[__i__][entrada]" class="form-control form-control-sm js-entrada" required></td>
            <td><input type="time" name="extras[__i__][saida]" class="form-control form-control-sm js-saida" required></td>
            <td><input type="text" name="extras[__i__][horas]" class="form-control form-control-sm js-horas" value="<?php echo hhmm(CARGA_PADRAO_EXTRA); ?>" data-valor="<?php echo CARGA_PADRAO_EXTRA; ?>" data-carga="<?php echo CARGA_PADRAO_EXTRA; ?>" readonly tabindex="-1" title="Calculado pela entrada e saída"></td>
            <td><input type="text" name="extras[__i__][observacao]" class="form-control form-control-sm" placeholder="Motivo do extra..."></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-light-app text-danger" onclick="this.closest('tr').remove(); atualizarVazio();" title="Remover"><i class="bi bi-trash3 me-0"></i></button></td>
        </tr>
    </template>


    <?php endif; ?>
</div>

<script>
let indiceExtra = <?php echo count($extras); ?>;

// Calcula as horas entre entrada e saída (considera virada de dia)
function calcularHoras(entrada, saida) {
    if (!entrada || !saida) return null;
    const [h1, m1] = entrada.split(':').map(Number);
    const [h2, m2] = saida.split(':').map(Number);
    let minutos = (h2 * 60 + m2) - (h1 * 60 + m1);
    if (minutos < 0) minutos += 24 * 60;
    return minutos / 60;
}

// Horas são exibidas em horas:minutos; o valor decimal fica em data-valor
function hhmm(horas) {
    const minutos = Math.round(Math.abs(horas) * 60);
    return String(Math.floor(minutos / 60)).padStart(2, '0') + ':' + String(minutos % 60).padStart(2, '0');
}

function definirHoras(campo, horas) {
    campo.dataset.valor = horas;
    // No quadro a coluna exibe a carga horária; as horas feitas só alimentam o saldo
    campo.value = hhmm(campo.classList.contains('js-mostra-carga') ? parseFloat(campo.dataset.carga) || 0 : horas);
}

function lerHoras(campo) {
    return parseFloat(campo.dataset.valor) || 0;
}

// Ao mudar entrada/saída, preenche as horas da mesma linha
document.addEventListener('change', function (ev) {
    const alvo = ev.target;
    const linha = alvo.closest('.js-linha');
    if (!linha) return;
    if (alvo.classList.contains('js-entrada') || alvo.classList.contains('js-saida')) {
        const campoHoras = linha.querySelector('.js-horas');
        const horas = calcularHoras(linha.querySelector('.js-entrada').value, linha.querySelector('.js-saida').value);
        // Sem horário completo, vale a carga padrão
        definirHoras(campoHoras, horas !== null ? horas : parseFloat(campoHoras.dataset.padrao || campoHoras.dataset.carga || 8));
    }
    // Ao escolher a diarista, usa a carga dela como padrão de horas quando não há horário informado
    if (alvo.classList.contains('js-diarista')) {
        const opcao = alvo.selectedOptions[0];
        const campoHoras = linha.querySelector('.js-horas');
        // A diarista é comparada com a própria jornada (carga do cadastro dela)
        if (opcao && opcao.dataset.carga) {
            campoHoras.dataset.carga = opcao.dataset.carga;
            if (campoHoras.classList.contains('js-mostra-carga')) campoHoras.value = hhmm(parseFloat(opcao.dataset.carga));
        }
        campoHoras.dataset.padrao = opcao && opcao.dataset.carga ? opcao.dataset.carga : '';
        if (campoHoras.dataset.padrao && calcularHoras(linha.querySelector('.js-entrada').value, linha.querySelector('.js-saida').value) === null) {
            definirHoras(campoHoras, parseFloat(campoHoras.dataset.padrao));
        }
    }
    atualizarSaldo(linha);
});

// Saldo do dia: horas feitas menos a carga (vermelho abaixo, azul-marinho igual, verde acima)
function atualizarSaldo(linha) {
    const saldo = linha && linha.querySelector('.js-saldo');
    if (!saldo) return;
    const campoHoras = linha.querySelector('.js-horas');
    const diferenca = Math.round((lerHoras(campoHoras) - (parseFloat(campoHoras.dataset.carga) || 0)) * 60); // em minutos
    const sinal = diferenca > 0 ? '+' : (diferenca < 0 ? '−' : '');
    saldo.textContent = sinal + hhmm(diferenca / 60);
    saldo.className = 'saldo-dia js-saldo ' + (diferenca < 0 ? 'saldo-abaixo' : (diferenca > 0 ? 'saldo-acima' : 'saldo-igual'));
}

// Falta e Substituído zeram as horas; Justificado fixa a carga padrão; Substituído também abre a linha da diarista
function toggleHoras(selectElement) {
    const linha = selectElement.closest('tr');
    const inputHoras = linha.querySelector('.js-horas');
    const campos = linha.querySelectorAll('.js-entrada, .js-saida, .js-horas');
    if (selectElement.value === 'Falta' || selectElement.value === 'Substituido') {
        definirHoras(inputHoras, 0);
        campos.forEach(c => c.disabled = true);
    } else if (selectElement.value === 'Justificado') {
        linha.querySelector('.js-entrada').value = '';
        linha.querySelector('.js-saida').value = '';
        definirHoras(inputHoras, parseFloat(inputHoras.dataset.carga || 8));
        campos.forEach(c => c.disabled = true);
    } else {
        campos.forEach(c => c.disabled = false);
        if (lerHoras(inputHoras) == 0) definirHoras(inputHoras, parseFloat(inputHoras.dataset.carga || 8));
    }
    atualizarSaldo(linha);
    mostrarSubstituicao(linha.nextElementSibling, selectElement.value === 'Substituido');
}

// Ao tentar salvar, destaca em vermelho o que falta preencher
document.addEventListener('click', function (ev) {
    const botao = ev.target.closest('button[type=submit]');
    if (!botao || !botao.form || !botao.form.querySelector('input[name=salvar_frequencia]')) return;
    const form = botao.form;
    form.classList.add('foi-validado');
    const aviso = document.getElementById('aviso_incompleto');
    if (!form.checkValidity()) {
        aviso.style.display = '';
    } else {
        aviso.style.display = 'none';
    }
});

function mostrarSubstituicao(linhaSub, mostrar) {
    if (!linhaSub || !linhaSub.classList.contains('linha-substituicao')) return;
    linhaSub.style.display = mostrar ? '' : 'none';
    linhaSub.querySelectorAll('select, input').forEach(c => c.disabled = !mostrar);
    const diarista = linhaSub.querySelector('.js-diarista');
    diarista.required = mostrar;
    if (mostrar) {
        diarista.focus();
    } else {
        diarista.value = '';
    }
}

function adicionarExtra() {
    const html = document.getElementById('modelo_extra').innerHTML.replace(/__i__/g, indiceExtra++);
    document.getElementById('extras_vazio').insertAdjacentHTML('beforebegin', html);
    atualizarVazio();
}

function atualizarVazio() {
    const vazio = document.getElementById('extras_vazio');
    vazio.style.display = document.querySelectorAll('#lista_extras .linha-extra').length ? 'none' : '';
}
</script>

<?php render_rodape(); ?>
