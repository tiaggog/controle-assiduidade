<?php
require_once 'conexao.php';

// Saída só por POST (o token do formulário já foi validado na conexão)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION = [];
    session_destroy();
}
header('Location: login.php');
exit;
