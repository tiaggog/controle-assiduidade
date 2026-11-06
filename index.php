<?php
/**
 * Página inicial: redireciona cada perfil para a primeira tela que ele pode acessar.
 */
require_once 'conexao.php';

// Página inicial: leva cada perfil para a primeira tela liberada
header('Location: ' . pagina_inicial(usuario_atual()['perfil']));
exit;
