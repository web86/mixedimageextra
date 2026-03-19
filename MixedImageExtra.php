<?php
if ($modx->event->name === 'OnDocFormPrerender') {

    /** @var modManagerController $controller */
    $controller = $modx->controller;

    $controller->addLastJavascript(
        $modx->getOption('assets_url') . 'components/mixedimageextra/js/mgr/override.js'
    );
}
