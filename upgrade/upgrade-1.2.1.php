<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_1_2_1($module)
{
    $ok = true;

    // ---- Configuración por defecto de los nuevos bloques (filtros, KPIs, cron) ----
    Configuration::updateValue('JOSRA_DESIST_CARRIERS_EXCLUIDOS', '');
    Configuration::updateValue('JOSRA_DESIST_ESTADOS_EXCLUIDOS', '');
    Configuration::updateValue('JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS', '');
    Configuration::updateValue('JOSRA_DESIST_EXCLUIR_SI_EMPRESA', 0);

    if (!Configuration::hasKey('JOSRA_DESIST_CRON_TOKEN') || !Configuration::get('JOSRA_DESIST_CRON_TOKEN')) {
        Configuration::updateValue('JOSRA_DESIST_CRON_TOKEN', Tools::passwdGen(32));
    }

    // ---- ALTER TABLE seguro: comprobamos columnas existentes antes de añadir ----
    $tabla = _DB_PREFIX_ . 'josra_desistimiento';
    $columnasExistentes = [];
    $rows = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $tabla . '`');
    if ($rows) {
        foreach ($rows as $row) {
            $columnasExistentes[] = $row['Field'];
        }
    }

    $columnasNuevas = [
        'fecha_limite'         => "ADD `fecha_limite` DATETIME NULL",
        'motivo_rechazo'       => "ADD `motivo_rechazo` TEXT",
        'notas_internas'       => "ADD `notas_internas` TEXT",
        'bienes_recibidos'     => "ADD `bienes_recibidos` TINYINT(1) NOT NULL DEFAULT 0",
        'reembolsado'          => "ADD `reembolsado` TINYINT(1) NOT NULL DEFAULT 0",
        'recordatorio_enviado' => "ADD `recordatorio_enviado` TINYINT(1) NOT NULL DEFAULT 0",
        'fecha_aprobado'       => "ADD `fecha_aprobado` DATETIME NULL",
        'fecha_rechazado'      => "ADD `fecha_rechazado` DATETIME NULL",
        'fecha_recibido'       => "ADD `fecha_recibido` DATETIME NULL",
        'fecha_reembolsado'    => "ADD `fecha_reembolsado` DATETIME NULL",
        'auditoria'            => "ADD `auditoria` TEXT",
        'token'                => "ADD `token` VARCHAR(64) NOT NULL DEFAULT ''",
    ];

    foreach ($columnasNuevas as $nombreColumna => $clausulaAdd) {
        if (!in_array($nombreColumna, $columnasExistentes, true)) {
            $ok = Db::getInstance()->execute(
                'ALTER TABLE `' . $tabla . '` ' . $clausulaAdd
            ) && $ok;
        }
    }

    // ---- Tabla de exclusiones (copiada de installSql) ----
    $ok = Db::getInstance()->execute('
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento_exclusion` (
            `id_exclusion`    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `tipo`            VARCHAR(20) NOT NULL DEFAULT \'producto\',
            `id_objeto`       INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `modo`            VARCHAR(20) NOT NULL DEFAULT \'excluir\',
            `dias_ampliados`  INT(11) NULL,
            `date_add`        DATETIME NOT NULL,
            PRIMARY KEY (`id_exclusion`),
            KEY `idx_tipo_objeto` (`tipo`, `id_objeto`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4
    ') && $ok;

    // ---- Migración por idioma: asegurar que la etiqueta de "Otro motivo" existe ----
    foreach (Language::getLanguages(false) as $lang) {
        $idLang = (int) $lang['id_lang'];
        if (!Configuration::hasKey('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang)) {
            Configuration::updateValue('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang, $module->l('Otro motivo'), true);
        }
        if (!Configuration::hasKey('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang)) {
            Configuration::updateValue('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang, '', true);
        }
        if (!Configuration::hasKey('JOSRA_DESIST_MOTIVOS_' . $idLang)) {
            Configuration::updateValue(
                'JOSRA_DESIST_MOTIVOS_' . $idLang,
                $module->serializarMotivos($module->getMotivosPorDefecto()),
                true
            );
        }
    }

    return $ok;
}
