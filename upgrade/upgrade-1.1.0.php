<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_1_1_0($module)
{
    // Crear el estado de pedido "En desistimiento" si no existe
    return $module->installOrderState();
}
