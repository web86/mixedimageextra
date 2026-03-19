<?php

// ================================
// 🔒 BOOTSTRAP MODX
// ================================
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';


// ================================
// 🔒 SECURITY CHECKS
// ================================

// 1. Только для авторизованных пользователей менеджера
if (!$modx->user || !$modx->user->isAuthenticated('mgr')) {
    @session_write_close();
    exit('Access denied');
}

// 2. Только в контексте mgr
if ($modx->context->get('key') !== 'mgr') {
    @session_write_close();
    exit('Invalid context');
}

// 3. Ограничиваем допустимые действия
$allowedActions = [
    'file/upload',
    'file/remove',
];

$action = $modx->getOption('action', $_REQUEST, '');

if (!in_array($action, $allowedActions)) {
    @session_write_close();
    exit('Invalid action');
}


// ================================
// 🚀 PROCESSOR
// ================================

$corePath = $modx->getOption('core_path') . 'components/mixedimageextra/';

$modx->request->handleRequest([
    'processors_path' => $corePath . 'processors/',
    'location' => '',
]);