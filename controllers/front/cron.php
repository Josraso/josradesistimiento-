<?php
/**
 * Controlador front: tarea cron diaria.
 * - Expira solicitudes pendientes cuya fecha límite ha pasado.
 * - Envía recordatorios a clientes con solicitudes próximas a vencer.
 * Se valida con un token (no requiere sesión ni CSRF, es invocado por
 * un planificador externo / cron del servidor).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosradesistimientoCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        // No llamamos a parent::initContent() para evitar renderizar la página completa de PS

        header('Content-Type: application/json; charset=utf-8');

        $token = Tools::getValue('token');
        $tokenConfigurado = Configuration::get('JOSRA_DESIST_CRON_TOKEN');

        if (!$tokenConfigurado || !$token || !hash_equals((string) $tokenConfigurado, (string) $token)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            exit;
        }

        $resumen = [
            'ok'                  => true,
            'fecha'               => date('Y-m-d H:i:s'),
            'solicitudes_expiradas' => 0,
            'recordatorios_enviados' => 0,
        ];

        $resumen['solicitudes_expiradas'] = $this->expirarSolicitudesVencidas();
        $resumen['recordatorios_enviados'] = $this->enviarRecordatorios();

        echo json_encode($resumen);
        exit;
    }

    /**
     * Expira las solicitudes pendientes cuya fecha_limite ya ha pasado.
     */
    private function expirarSolicitudesVencidas()
    {
        $tabla = _DB_PREFIX_ . 'josra_desistimiento';

        $vencidas = Db::getInstance()->executeS(
            'SELECT `id_desistimiento` FROM `' . $tabla . '`
             WHERE `estado` IN (\'pendiente\', \'aprobado\')
             AND `fecha_limite` IS NOT NULL
             AND `fecha_limite` < NOW()'
        );

        if (!$vencidas) {
            return 0;
        }

        $contador = 0;
        foreach ($vencidas as $row) {
            $idDesistimiento = (int) $row['id_desistimiento'];
            Db::getInstance()->update('josra_desistimiento', [
                'estado'          => 'expirado',
                'fecha_procesado' => date('Y-m-d H:i:s'),
            ], 'id_desistimiento = ' . $idDesistimiento);

            $this->module->addAuditoria($idDesistimiento, $this->module->l('Solicitud expirada automáticamente por la tarea cron (fecha límite superada).'));
            $contador++;
        }

        return $contador;
    }

    /**
     * Envía un email de recordatorio a las solicitudes pendientes cuya fecha
     * límite está a 3 días o menos, y que no han recibido recordatorio aún.
     */
    private function enviarRecordatorios()
    {
        $tabla = _DB_PREFIX_ . 'josra_desistimiento';

        $proximas = Db::getInstance()->executeS(
            'SELECT * FROM `' . $tabla . '`
             WHERE `estado` IN (\'pendiente\', \'aprobado\')
             AND `recordatorio_enviado` = 0
             AND `fecha_limite` IS NOT NULL
             AND `fecha_limite` BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)'
        );

        if (!$proximas) {
            return 0;
        }

        $shopName = Configuration::get('PS_SHOP_NAME');
        $contador = 0;

        foreach ($proximas as $solicitud) {
            $idDesistimiento = (int) $solicitud['id_desistimiento'];
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

            $templateVars = [
                '{nombre}'           => $solicitud['nombre'],
                '{referencia}'       => $solicitud['reference'],
                '{fecha_limite}'     => $solicitud['fecha_limite'],
                '{shop_name}'        => $shopName,
                '{id_desistimiento}' => $idDesistimiento,
            ];

            $enviado = Mail::Send(
                $idLang,
                'desistimiento_recordatorio',
                $this->module->l('Recordatorio: tu solicitud de desistimiento — ') . $shopName,
                $templateVars,
                $solicitud['email'],
                $solicitud['nombre'],
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . $this->module->name . '/mails/'
            );

            if ($enviado) {
                Db::getInstance()->update('josra_desistimiento', [
                    'recordatorio_enviado' => 1,
                ], 'id_desistimiento = ' . $idDesistimiento);

                $this->module->addAuditoria($idDesistimiento, $this->module->l('Recordatorio de plazo enviado al cliente (tarea cron).'));
                $contador++;
            }
        }

        return $contador;
    }
}
