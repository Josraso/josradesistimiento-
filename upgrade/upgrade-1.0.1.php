<?php
/**
 * upgrade/upgrade-1.0.1.php
 * Ejemplo de script de actualización para versiones futuras.
 * PrestaShop ejecuta automáticamente los scripts upgrade-X.Y.Z.php
 * en orden al actualizar el módulo desde el backoffice.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    // Ejemplo: añadir columna para rastrear IP de retención
    // Db::getInstance()->execute(
    //     'ALTER TABLE `' . _DB_PREFIX_ . 'josra_desistimiento`
    //      ADD COLUMN `ip_retencion` VARCHAR(45) NOT NULL DEFAULT \'\' AFTER `ip`'
    // );
    return true;
}
