
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `controle_assiduidade`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `controle_assiduidade` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `funcionario_id` int(11) NOT NULL,
  `empresa_id` int(11) DEFAULT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `cargo` enum('Lider','Conferente','Ajudante') DEFAULT NULL,
  `turno` enum('T1','T3') DEFAULT NULL,
  `data_referencia` date NOT NULL,
  `presenca` enum('Presente','Falta','Justificado') NOT NULL,
  `dia_especial` tinyint(1) NOT NULL DEFAULT 0,
  `doc_irregular` varchar(255) DEFAULT NULL,
  `hora_entrada` time DEFAULT NULL,
  `hora_saida` time DEFAULT NULL,
  `horas_trabalhadas` decimal(4,2) NOT NULL DEFAULT 0.00,
  `observacao` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unica_presenca_diaria` (`funcionario_id`,`data_referencia`),
  KEY `idx_assiduidade_empresa_data` (`empresa_id`,`data_referencia`),
  CONSTRAINT `controle_assiduidade_ibfk_1` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documentos_tipos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documentos_tipos` (
  `id` tinyint(3) unsigned NOT NULL,
  `nome` varchar(60) NOT NULL,
  `exigido` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `empresas_terceirizadas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empresas_terceirizadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `razao_social` varchar(255) NOT NULL,
  `cnpj` varchar(20) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faturas_salvas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `faturas_salvas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(80) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `funcionario_id` int(11) DEFAULT NULL,
  `funcionario_nome` varchar(100) DEFAULT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `dias_uteis` int(11) DEFAULT NULL,
  `valor_hn` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_he` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_hn` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_he` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_func` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_sub` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_extra` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_geral` decimal(12,2) NOT NULL DEFAULT 0.00,
  `dados` longtext NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fatura_codigo` (`codigo`),
  KEY `idx_fatura_periodo` (`data_inicio`,`data_fim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feriados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feriados` (
  `filial_id` int(11) NOT NULL,
  `data` date NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`filial_id`,`data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `filiais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `filiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(60) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_filial_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funcionario_documentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funcionario_documentos` (
  `funcionario_id` int(11) NOT NULL,
  `tipo_id` tinyint(3) unsigned NOT NULL,
  `data_vencimento` date DEFAULT NULL,
  `arquivo` varchar(80) DEFAULT NULL,
  `arquivo_nome` varchar(255) DEFAULT NULL,
  `arquivo_mime` varchar(50) DEFAULT NULL,
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`funcionario_id`,`tipo_id`),
  KEY `funcionario_documentos_tipo` (`tipo_id`),
  CONSTRAINT `funcionario_documentos_funcionario` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `funcionario_documentos_tipo` FOREIGN KEY (`tipo_id`) REFERENCES `documentos_tipos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funcionarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funcionarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) DEFAULT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `carga_horaria` decimal(5,2) NOT NULL DEFAULT 8.00,
  `tipo` enum('Funcionario','Diarista') NOT NULL DEFAULT 'Funcionario',
  `cargo` enum('Lider','Conferente','Ajudante') DEFAULT NULL,
  `turno` enum('T1','T3') DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `data_inativacao` date DEFAULT NULL,
  `data_cadastro` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `empresa_id` (`empresa_id`),
  KEY `idx_funcionarios_filial` (`filial_id`),
  CONSTRAINT `funcionarios_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresas_terceirizadas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lancamentos_diaristas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lancamentos_diaristas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `cargo` enum('Lider','Conferente','Ajudante') DEFAULT NULL,
  `turno` enum('T1','T3') DEFAULT NULL,
  `data_referencia` date NOT NULL,
  `diarista_id` int(11) DEFAULT NULL,
  `substituido_id` int(11) DEFAULT NULL,
  `nome_avulso` varchar(100) DEFAULT NULL,
  `cpf_avulso` varchar(14) DEFAULT NULL,
  `hora_entrada` time DEFAULT NULL,
  `hora_saida` time DEFAULT NULL,
  `horas_trabalhadas` decimal(4,2) NOT NULL DEFAULT 0.00,
  `observacao` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_empresa_data` (`empresa_id`,`data_referencia`),
  KEY `lancamentos_diaristas_diarista` (`diarista_id`),
  KEY `lancamentos_diaristas_substituido` (`substituido_id`),
  KEY `idx_lancamentos_filial` (`filial_id`),
  CONSTRAINT `lancamentos_diaristas_diarista` FOREIGN KEY (`diarista_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lancamentos_diaristas_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas_terceirizadas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lancamentos_diaristas_substituido` FOREIGN KEY (`substituido_id`) REFERENCES `funcionarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notificacoes_vistas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notificacoes_vistas` (
  `usuario_id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `filial_id` int(11) NOT NULL DEFAULT 0,
  `data_referencia` date NOT NULL,
  `visto_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`usuario_id`,`empresa_id`,`filial_id`,`data_referencia`),
  CONSTRAINT `notificacoes_vistas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `login` varchar(50) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `perfil` enum('admin','financeiro','supervisao','gestao','rh') NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `ultimo_acesso` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `valores_categoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `valores_categoria` (
  `filial_id` int(11) NOT NULL,
  `categoria` varchar(20) NOT NULL,
  `turno` enum('T1','T3') NOT NULL,
  `valor` decimal(12,2) NOT NULL DEFAULT 0.00,
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`filial_id`,`categoria`,`turno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

