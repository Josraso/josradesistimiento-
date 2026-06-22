<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_1_2_0($module)
{
    Configuration::updateValue('JOSRA_DESIST_GASTOS_DEVOLUCION', 'cliente');
    Configuration::updateValue('JOSRA_DESIST_DIRECCION_DEVOLUCION', '');
    Configuration::updateValue('JOSRA_DESIST_POLITICA_URL', '');
    Configuration::updateValue('JOSRA_DESIST_EMAIL_REMITENTE', '');
    Configuration::updateValue('JOSRA_DESIST_EMAIL_REPLYTO', '');
    Configuration::updateValue('JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO', 0);

    foreach (Language::getLanguages(false) as $lang) {
        $idLang = (int) $lang['id_lang'];

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

    return true;
}
