<?php
/**
 * Conexão com o banco e estrutura automática
 *
 * - Abre a conexão PDO com o MySQL/MariaDB (ajuste host, banco, usuário e senha abaixo);
 * - Cria/atualiza tabelas e colunas que o sistema usa (migrações simples, só adicionam o que falta);
 * - Carrega as funções compartilhadas (funcoes.php) e aplica o controle de login e permissão
 *   (proteger_pagina) em toda página que inclui este arquivo.
 */
// Define o fuso horário padrão para a sua região
date_default_timezone_set('America/Fortaleza');

$host    = 'localhost';
$dbname  = 'assiduidade_db'; // Nome do seu banco de dados
$user    = 'root';           // Usuário padrão do XAMPP
$password = '';              // Senha padrão do XAMPP (vazia)

try {
    // Cria a conexão via PDO que será usada pelos outros arquivos
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $password);
    
    // Configura o PDO para disparar erros na tela caso haja falha na consulta SQL
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
} catch (PDOException $e) {
    // Se a conexão falhar, mata a execução e exibe o erro
    die("Erro ao conectar com o banco de dados: " . $e->getMessage());
}

// Garante que as tabelas tenham as colunas usadas pelo sistema.
// Se a coluna ja existir, nada sera alterado.
$colunas_necessarias = [
    ['funcionarios', 'carga_horaria', "ALTER TABLE funcionarios ADD COLUMN carga_horaria DECIMAL(5,2) NOT NULL DEFAULT 8.00 AFTER cpf"],
    ['funcionarios', 'tipo', "ALTER TABLE funcionarios ADD COLUMN tipo ENUM('Funcionario','Diarista') NOT NULL DEFAULT 'Funcionario' AFTER carga_horaria"],
    ['funcionarios', 'data_inativacao', "ALTER TABLE funcionarios ADD COLUMN data_inativacao DATE NULL DEFAULT NULL AFTER status"],
    // Data de cadastro: dias anteriores a ela não geram pendência de frequência (cadastros antigos ficam NULL)
    ['funcionarios', 'data_cadastro', "ALTER TABLE funcionarios ADD COLUMN data_cadastro DATE NULL DEFAULT NULL AFTER data_inativacao"],
    ['controle_assiduidade', 'hora_entrada', "ALTER TABLE controle_assiduidade ADD COLUMN hora_entrada TIME NULL DEFAULT NULL AFTER presenca"],
    ['controle_assiduidade', 'hora_saida', "ALTER TABLE controle_assiduidade ADD COLUMN hora_saida TIME NULL DEFAULT NULL AFTER hora_entrada"],
];

try {
    $stmt_coluna = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabela AND COLUMN_NAME = :coluna");
    foreach ($colunas_necessarias as [$tabela, $coluna, $sql_alter]) {
        $stmt_coluna->execute([':tabela' => $tabela, ':coluna' => $coluna]);
        if ((int)$stmt_coluna->fetchColumn() === 0) {
            $pdo->exec($sql_alter);
        }
    }

    // Diaristas podem atender qualquer empresa, por isso o vinculo passa a ser opcional
    $stmt_nulo = $pdo->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'empresa_id'");
    if ($stmt_nulo->fetchColumn() === 'NO') {
        $pdo->exec("ALTER TABLE funcionarios MODIFY empresa_id INT(11) NULL");
    }

    // Lancamentos de diaristas: substituicoes (substituido_id preenchido) e extras do dia (substituido_id nulo)
    $pdo->exec("CREATE TABLE IF NOT EXISTS lancamentos_diaristas (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        empresa_id INT(11) NOT NULL,
        data_referencia DATE NOT NULL,
        diarista_id INT(11) NOT NULL,
        substituido_id INT(11) NULL DEFAULT NULL,
        hora_entrada TIME NULL DEFAULT NULL,
        hora_saida TIME NULL DEFAULT NULL,
        horas_trabalhadas DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        observacao TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_empresa_data (empresa_id, data_referencia),
        CONSTRAINT lancamentos_diaristas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas_terceirizadas (id) ON DELETE CASCADE,
        CONSTRAINT lancamentos_diaristas_diarista FOREIGN KEY (diarista_id) REFERENCES funcionarios (id) ON DELETE CASCADE,
        CONSTRAINT lancamentos_diaristas_substituido FOREIGN KEY (substituido_id) REFERENCES funcionarios (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Extras são pessoas avulsas (sem cadastro): diarista_id fica vazio e o nome/CPF vão no próprio lançamento
    $stmt_coluna->execute([':tabela' => 'lancamentos_diaristas', ':coluna' => 'nome_avulso']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE lancamentos_diaristas
                    MODIFY diarista_id INT(11) NULL,
                    ADD COLUMN nome_avulso VARCHAR(100) NULL DEFAULT NULL AFTER substituido_id,
                    ADD COLUMN cpf_avulso VARCHAR(14) NULL DEFAULT NULL AFTER nome_avulso");
    }

    // Usuarios do sistema e seus perfis de acesso
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT(11) NOT NULL AUTO_INCREMENT,
        nome VARCHAR(100) NOT NULL,
        login VARCHAR(50) NOT NULL,
        senha_hash VARCHAR(255) NOT NULL,
        perfil ENUM('admin','financeiro','supervisao','gestao','rh') NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_acesso DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_login (login)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Perfil Recursos Humanos (RH): acesso aos documentos
    $tipo_perfil = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'perfil'")->fetchColumn();
    if ($tipo_perfil && strpos($tipo_perfil, "'rh'") === false) {
        $pdo->exec("ALTER TABLE usuarios MODIFY perfil ENUM('admin','financeiro','supervisao','gestao','rh') NOT NULL");
    }

    // Controle de documentos: tipos de documento (o admin adiciona, renomeia, exige ou exclui; começa com 8)
    // e a data de validade de cada documento por colaborador
    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_tipos (
        id TINYINT(3) UNSIGNED NOT NULL,
        nome VARCHAR(60) NOT NULL,
        exigido TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if ((int)$pdo->query("SELECT COUNT(*) FROM documentos_tipos")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO documentos_tipos (id, nome) VALUES
            (1, 'Documento 1'), (2, 'Documento 2'), (3, 'Documento 3'), (4, 'Documento 4'),
            (5, 'Documento 5'), (6, 'Documento 6'), (7, 'Documento 7'), (8, 'Documento 8')");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS funcionario_documentos (
        funcionario_id INT(11) NOT NULL,
        tipo_id TINYINT(3) UNSIGNED NOT NULL,
        data_vencimento DATE NULL DEFAULT NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (funcionario_id, tipo_id),
        CONSTRAINT funcionario_documentos_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios (id) ON DELETE CASCADE,
        CONSTRAINT funcionario_documentos_tipo FOREIGN KEY (tipo_id) REFERENCES documentos_tipos (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Notificações do sininho (dia + empresa sem frequência) que cada usuário já abriu
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_vistas (
        usuario_id INT(11) NOT NULL,
        empresa_id INT(11) NOT NULL,
        data_referencia DATE NOT NULL,
        visto_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, empresa_id, data_referencia),
        CONSTRAINT notificacoes_vistas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Filiais: cada funcionário/diarista pertence a uma filial e a supervisão só enxerga a filial dela
    $pdo->exec("CREATE TABLE IF NOT EXISTS filiais (
        id INT(11) NOT NULL AUTO_INCREMENT,
        nome VARCHAR(60) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uk_filial_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    if ((int)$pdo->query("SELECT COUNT(*) FROM filiais")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO filiais (nome) VALUES ('FORTALEZA'), ('IMPERATRIZ'), ('RECIFE'), ('SÃO LUÍS'), ('SOBRAL'), ('TERESINA')");
    }
    $colunas_filial = [
        ['funcionarios', "ALTER TABLE funcionarios ADD COLUMN filial_id INT(11) NULL DEFAULT NULL AFTER empresa_id, ADD KEY idx_funcionarios_filial (filial_id)"],
        ['usuarios', "ALTER TABLE usuarios ADD COLUMN filial_id INT(11) NULL DEFAULT NULL AFTER perfil"],
        // Lançamentos de diaristas guardam a filial da folha (extras não têm cadastro para saber a filial)
        ['lancamentos_diaristas', "ALTER TABLE lancamentos_diaristas ADD COLUMN filial_id INT(11) NULL DEFAULT NULL AFTER empresa_id, ADD KEY idx_lancamentos_filial (filial_id)"],
        // Notificação vista passa a ser por dia + empresa + filial
        ['notificacoes_vistas', "ALTER TABLE notificacoes_vistas ADD COLUMN filial_id INT(11) NOT NULL DEFAULT 0 AFTER empresa_id, DROP PRIMARY KEY, ADD PRIMARY KEY (usuario_id, empresa_id, filial_id, data_referencia)"],
    ];
    foreach ($colunas_filial as [$tabela, $sql_alter]) {
        $stmt_coluna->execute([':tabela' => $tabela, ':coluna' => 'filial_id']);
        if ((int)$stmt_coluna->fetchColumn() === 0) {
            $pdo->exec($sql_alter);
            if ($tabela === 'lancamentos_diaristas') {
                // Substituições já lançadas herdam a filial do funcionário substituído
                $pdo->exec("UPDATE lancamentos_diaristas l INNER JOIN funcionarios f ON f.id = l.substituido_id SET l.filial_id = f.filial_id");
            }
        }
    }

    // A frequência guarda a empresa e a filial do dia lançado: trocar o tipo, a empresa ou a filial
    // do colaborador depois não tira o histórico dos relatórios
    $stmt_coluna->execute([':tabela' => 'controle_assiduidade', ':coluna' => 'empresa_id']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE controle_assiduidade
                    ADD COLUMN empresa_id INT(11) NULL DEFAULT NULL AFTER funcionario_id,
                    ADD COLUMN filial_id INT(11) NULL DEFAULT NULL AFTER empresa_id,
                    ADD KEY idx_assiduidade_empresa_data (empresa_id, data_referencia)");
        $pdo->exec("UPDATE controle_assiduidade c INNER JOIN funcionarios f ON f.id = c.funcionario_id
                    SET c.empresa_id = f.empresa_id, c.filial_id = f.filial_id");
    }

    // Arquivo anexado ao documento (foto ou PDF), guardado em uploads/documentos
    $stmt_coluna->execute([':tabela' => 'funcionario_documentos', ':coluna' => 'arquivo']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE funcionario_documentos
                    ADD COLUMN arquivo VARCHAR(80) NULL DEFAULT NULL AFTER data_vencimento,
                    ADD COLUMN arquivo_nome VARCHAR(255) NULL DEFAULT NULL AFTER arquivo,
                    ADD COLUMN arquivo_mime VARCHAR(50) NULL DEFAULT NULL AFTER arquivo_nome");
    }

    // Pré-faturas salvas: guardam os valores do momento em que foram salvas (histórico do Faturamento)
    $pdo->exec("CREATE TABLE IF NOT EXISTS faturas_salvas (
        id INT(11) NOT NULL AUTO_INCREMENT,
        codigo VARCHAR(80) NOT NULL,
        empresa_id INT(11) NOT NULL,
        filial_id INT(11) NULL DEFAULT NULL,
        funcionario_id INT(11) NULL DEFAULT NULL,
        funcionario_nome VARCHAR(100) NULL DEFAULT NULL,
        data_inicio DATE NOT NULL,
        data_fim DATE NOT NULL,
        valor_hn DECIMAL(10,2) NOT NULL DEFAULT 0,
        valor_he DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_hn DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_he DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_func DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_sub DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_extra DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_geral DECIMAL(12,2) NOT NULL DEFAULT 0,
        dados LONGTEXT NOT NULL,
        usuario_id INT(11) NULL DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_fatura_codigo (codigo),
        KEY idx_fatura_periodo (data_inicio, data_fim)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Faturamento por dias trabalhados
    // Cargo (só funcionário fixo) e turno (T1 diurno / T3 noturno) de cada colaborador
    $stmt_coluna->execute([':tabela' => 'funcionarios', ':coluna' => 'cargo']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE funcionarios
                    ADD COLUMN cargo ENUM('Lider','Conferente','Ajudante') NULL DEFAULT NULL AFTER tipo,
                    ADD COLUMN turno ENUM('T1','T3') NULL DEFAULT NULL AFTER cargo");
    }
    // Cargo e turno ficam gravados no lançamento (trocar no cadastro depois não muda o que já foi lançado)
    $stmt_coluna->execute([':tabela' => 'controle_assiduidade', ':coluna' => 'cargo']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE controle_assiduidade
                    ADD COLUMN cargo ENUM('Lider','Conferente','Ajudante') NULL DEFAULT NULL AFTER filial_id,
                    ADD COLUMN turno ENUM('T1','T3') NULL DEFAULT NULL AFTER cargo");
    }
    $stmt_coluna->execute([':tabela' => 'lancamentos_diaristas', ':coluna' => 'turno']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE lancamentos_diaristas ADD COLUMN turno ENUM('T1','T3') NULL DEFAULT NULL AFTER filial_id");
    }
    // Cargo da diarista também fica gravado no lançamento (base do valor da substituição)
    $stmt_coluna->execute([':tabela' => 'lancamentos_diaristas', ':coluna' => 'cargo']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE lancamentos_diaristas ADD COLUMN cargo ENUM('Lider','Conferente','Ajudante') NULL DEFAULT NULL AFTER filial_id");
    }
    // Condição "Domingo/Feriado" do Lançar Frequência: presença em dia especial, cobrada pela coluna Domingos/Feriados
    $stmt_coluna->execute([':tabela' => 'controle_assiduidade', ':coluna' => 'dia_especial']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE controle_assiduidade ADD COLUMN dia_especial TINYINT(1) NOT NULL DEFAULT 0 AFTER presenca");
    }
    // Documentação irregular no dia do lançamento (texto das pendências; NULL = em dia): aparece nos relatórios
    $stmt_coluna->execute([':tabela' => 'controle_assiduidade', ':coluna' => 'doc_irregular']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE controle_assiduidade ADD COLUMN doc_irregular VARCHAR(255) NULL DEFAULT NULL AFTER dia_especial");
    }
    // Feriados (lançamentos antigos; domingo é automático)
    $pdo->exec("CREATE TABLE IF NOT EXISTS feriados (
        filial_id INT(11) NOT NULL,
        data DATE NOT NULL,
        usuario_id INT(11) NULL DEFAULT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (filial_id, data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    // Tabela de valores mensais por filial, categoria e turno
    $pdo->exec("CREATE TABLE IF NOT EXISTS valores_categoria (
        filial_id INT(11) NOT NULL,
        categoria VARCHAR(20) NOT NULL,
        turno ENUM('T1','T3') NOT NULL,
        valor DECIMAL(12,2) NOT NULL DEFAULT 0,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (filial_id, categoria, turno)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    $stmt_coluna->execute([':tabela' => 'faturas_salvas', ':coluna' => 'dias_uteis']);
    if ((int)$stmt_coluna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE faturas_salvas ADD COLUMN dias_uteis INT(11) NULL DEFAULT NULL AFTER data_fim");
    }
} catch (PDOException $e) {
    die("Erro ao validar estrutura do banco de dados: " . $e->getMessage());
}

require_once __DIR__ . '/funcoes.php';

// Toda página que inclui a conexão passa pelo controle de login e permissão
proteger_pagina($pdo);
