<?php
/**
 * Sair: encerra a sessão (somente por POST, com token de segurança) e volta para o login.
 */
require_once 'conexao.php';

// Saída só por POST (o token do formulário já foi validado na conexão)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    session_destroy();
}
header('Location: login.php');
exit;
