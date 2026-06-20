<?php
/**
 * Controlador front: gestiona el formulario de desistimiento (2 pasos)
 * y la confirmación. Compatible PS 1.7 / 8 / 9.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosradesistimientoDesistirModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $action = Tools::getValue('action', 'formulario');

        switch ($action) {
            case 'enviar':
                $this->procesarDesistimiento();
                break;
            case 'confirmar':
                $this->mostrarConfirmacion();
                break;
            case 'retencion':
                $this->procesarRetencion();
                break;
            default:
                $this->mostrarFormulario();
                break;
        }
    }

    /* =========================================================
     *  PASO 1: Formulario
     * ========================================================= */

    private function mostrarFormulario()
    {
        $dias = (int) Configuration::get('JOSRA_DESIST_DIAS') ?: 14;
        $retencion = (int) Configuration::get('JOSRA_DESIST_RETENCION');
        $bonoPct = (int) Configuration::get('JOSRA_DESIST_BONO_PORCENT');

        // Datos del cliente logueado (si existe)
        $nombre = '';
        $email  = '';
        $pedidos = [];

        if ($this->context->customer->isLogged()) {
            $nombre = $this->context->customer->firstname . ' ' . $this->context->customer->lastname;
            $email  = $this->context->customer->email;
            $pedidosRaw = $this->getPedidosCliente($this->context->customer->id, $dias);
            // Formatear precio en PHP para no depender de helpers Smarty
            foreach ($pedidosRaw as &$p) {
                $p['precio_formateado'] = number_format((float)$p['total_paid_tax_incl'], 2, ',', '.') . ' ' . $this->context->currency->sign;
            }
            unset($p);
            $pedidos = $pedidosRaw;
        }

        $motivos = [
            'arrepentimiento'  => $this->module->l('Me arrepentí de la compra'),
            'talla_color'      => $this->module->l('Talla / color incorrecto'),
            'no_esperado'      => $this->module->l('El producto no era lo esperado'),
            'retraso'          => $this->module->l('Tardó demasiado en llegar'),
            'defecto'          => $this->module->l('El producto llegó con defecto'),
            'otro'             => $this->module->l('Otro motivo'),
        ];

        $this->context->smarty->assign([
            'josra_dias'        => $dias,
            'josra_retencion'   => $retencion,
            'josra_bono_pct'    => $bonoPct,
            'josra_nombre'      => $nombre,
            'josra_email'       => $email,
            'josra_pedidos'     => $pedidos,
            'josra_motivos'     => $motivos,
            'josra_action_url'  => $this->context->link->getModuleLink(
                'josradesistimiento', 'desistir', [], true
            ),
            'josra_token'       => Tools::getToken(false),
        ]);

        $this->setTemplate('module:josradesistimiento/views/templates/front/formulario.tpl');
    }

    /* =========================================================
     *  PASO 2: Procesar solicitud
     * ========================================================= */

    private function procesarDesistimiento()
    {
        // Validación token CSRF
        if (!Tools::getToken(false) || Tools::getValue('token') !== Tools::getToken(false)) {
            $this->mostrarError($this->module->l('Token de seguridad inválido. Recargue la página.'));
            return;
        }

        $nombre     = pSQL(Tools::getValue('josra_nombre', ''));
        $email      = Tools::getValue('josra_email', '');
        $referencia = pSQL(Tools::getValue('josra_referencia', ''));
        $motivo     = pSQL(Tools::getValue('josra_motivo', ''));
        $comentario = pSQL(Tools::getValue('josra_comentario', ''));

        // Validaciones básicas
        $errores = [];

        if (empty($nombre)) {
            $errores[] = $this->module->l('El nombre es obligatorio.');
        }
        if (!Validate::isEmail($email)) {
            $errores[] = $this->module->l('El email no es válido.');
        }
        if (empty($referencia)) {
            $errores[] = $this->module->l('La referencia del pedido es obligatoria.');
        }
        if (empty($motivo)) {
            $errores[] = $this->module->l('Por favor selecciona un motivo.');
        }

        // Verificar que el pedido existe y está en plazo
        $order = null;
        if (!empty($referencia)) {
            $order = $this->getOrderByReference($referencia);
            if (!$order) {
                $errores[] = $this->module->l('No encontramos ningún pedido con esa referencia.');
            } else {
                $dias = (int) Configuration::get('JOSRA_DESIST_DIAS') ?: 14;
                if (!$this->estaEnPlazo($order, $dias)) {
                    $errores[] = $this->module->l(
                        'El plazo de desistimiento de ' . $dias . ' días desde la entrega ha expirado para este pedido.'
                    );
                }
                // Verificar que el email coincide con el del pedido
                $customerEmail = (new Customer($order->id_customer))->email;
                if (strtolower($email) !== strtolower($customerEmail) && !empty($customerEmail)) {
                    $errores[] = $this->module->l('El email no coincide con el registrado en el pedido.');
                }
            }
        }

        // Verificar duplicados
        if (empty($errores) && $this->yaExisteSolicitud($referencia)) {
            $errores[] = $this->module->l('Ya existe una solicitud de desistimiento para este pedido.');
        }

        if (!empty($errores)) {
            $this->context->smarty->assign('josra_errores', $errores);
            $this->mostrarFormulario();
            return;
        }

        // Guardar en BD
        $token      = md5(uniqid(rand(), true));
        $idOrder    = $order ? (int) $order->id : 0;
        $idCustomer = $order ? (int) $order->id_customer : 0;

        Db::getInstance()->insert('josra_desistimiento', [
            'id_order'        => $idOrder,
            'id_customer'     => $idCustomer,
            'reference'       => $referencia,
            'nombre'          => $nombre,
            'email'           => pSQL($email),
            'motivo'          => $motivo,
            'comentario'      => $comentario,
            'estado'          => 'pendiente',
            'ip'              => pSQL(Tools::getRemoteAddr()),
            'fecha_solicitud' => date('Y-m-d H:i:s'),
            'token'           => $token,
        ]);

        $idDesistimiento = (int) Db::getInstance()->Insert_ID();

        // Marcar el pedido como "En desistimiento" en PS
        if ($order) {
            $this->module->marcarPedidoEnDesistimiento($order, $idDesistimiento);
        }

        // Enviar emails
        if (Configuration::get('JOSRA_DESIST_EMAIL')) {
            $this->enviarEmailConfirmacion($email, $nombre, $referencia, $motivo, $idDesistimiento);
        }

        $retencion = (int) Configuration::get('JOSRA_DESIST_RETENCION');
        $bonoPct   = (int) Configuration::get('JOSRA_DESIST_BONO_PORCENT');

        // Textos de retención generados en PHP para evitar problemas con sprintf en Smarty
        $textoSaldo = 'Saldo en tienda con ' . $bonoPct . '% de bonificación';
        $descSaldo  = 'En lugar de un reembolso directo, recibe saldo en tienda con un ' . $bonoPct . '% extra. Puedes usarlo cuando quieras, sin caducidad.';

        $this->context->smarty->assign([
            'josra_nombre'           => $nombre,
            'josra_email'            => $email,
            'josra_referencia'       => $referencia,
            'josra_motivo'           => $motivo,
            'josra_id_desistimiento' => $idDesistimiento,
            'josra_token_solicitud'  => $token,
            'josra_retencion'        => $retencion,
            'josra_bono_pct'         => $bonoPct,
            'josra_texto_saldo'      => $textoSaldo,
            'josra_desc_saldo'       => $descSaldo,
            'josra_fecha'            => date('d/m/Y H:i:s'),
            'josra_msg_recibido'     => 'Hemos recibido correctamente tu solicitud de desistimiento para el pedido ' . $referencia . ' el ' . date('d/m/Y H:i:s') . '.',
            'josra_msg_email'        => 'Te hemos enviado un email de confirmación a ' . $email . ' con todos los detalles de tu solicitud.',
            'josra_action_url'       => $this->context->link->getModuleLink(
                'josradesistimiento', 'desistir', [], true
            ),
        ]);

        $this->setTemplate('module:josradesistimiento/views/templates/front/confirmacion.tpl');
    }

    /* =========================================================
     *  PASO 3 (opcional): Retención
     * ========================================================= */

    private function procesarRetencion()
    {
        $idDesistimiento = (int) Tools::getValue('josra_id_desistimiento');
        $tokenSolicitud  = pSQL(Tools::getValue('josra_token_solicitud', ''));
        $opcion          = pSQL(Tools::getValue('josra_opcion_retencion', ''));

        // Verificar el token de la solicitud
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento`
             WHERE `id_desistimiento` = ' . $idDesistimiento . '
             AND `token` = \'' . $tokenSolicitud . '\''
        );

        if (!$row) {
            $this->mostrarError($this->module->l('Solicitud no encontrada.'));
            return;
        }

        $opcionesValidas = ['saldo', 'cambio', 'continuar'];
        if (!in_array($opcion, $opcionesValidas)) {
            $opcion = 'continuar';
        }

        // Actualizar la opción elegida y el estado según decisión
        $nuevoEstado = ($opcion === 'continuar') ? 'pendiente' : 'retenido';

        Db::getInstance()->update('josra_desistimiento', [
            'opcion_retencion' => $opcion,
            'estado'           => $nuevoEstado,
            'fecha_procesado'  => ($opcion !== 'continuar') ? date('Y-m-d H:i:s') : null,
        ], 'id_desistimiento = ' . $idDesistimiento . ' AND token = \'' . $tokenSolicitud . '\'');

        $this->context->smarty->assign([
            'josra_opcion'     => $opcion,
            'josra_referencia' => $row['reference'],
            'josra_bono_pct'   => (int) Configuration::get('JOSRA_DESIST_BONO_PORCENT'),
            'josra_nombre'     => $row['nombre'],
        ]);

        $this->setTemplate('module:josradesistimiento/views/templates/front/retencion_ok.tpl');
    }

    /* =========================================================
     *  HELPERS
     * ========================================================= */

    private function mostrarError($mensaje)
    {
        $this->context->smarty->assign('josra_error_fatal', $mensaje);
        $this->setTemplate('module:josradesistimiento/views/templates/front/error.tpl');
    }

    private function getPedidosCliente($idCustomer, $dias)
    {
        $estadosExcluidos = $this->getEstadosExcluidos();
        $excluir = implode(',', array_map('intval', $estadosExcluidos));
        $fechaLimite = date('Y-m-d H:i:s', strtotime('-60 days'));

        $pedidos = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`total_paid_tax_incl`, o.`current_state`
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.`id_customer` = ' . (int) $idCustomer . '
             AND o.`date_add` >= \'' . pSQL($fechaLimite) . '\'
             AND o.`current_state` NOT IN (' . ($excluir ?: '0') . ')
             AND o.`reference` NOT IN (
                 SELECT `reference` FROM `' . _DB_PREFIX_ . 'josra_desistimiento`
                 WHERE `estado` != \'rechazado\'
             )
             ORDER BY o.`date_add` DESC
             LIMIT 20'
        );

        if (!$pedidos) {
            return [];
        }

        // Filtrar en PHP los que han superado el plazo desde la entrega
        $resultado = [];
        foreach ($pedidos as $p) {
            $order = new Order((int) $p['id_order']);
            if ($this->estaEnPlazo($order, $dias)) {
                $resultado[] = $p;
            }
        }
        return $resultado;
    }

    private function getEstadosExcluidos()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT os.`id_order_state`
             FROM `' . _DB_PREFIX_ . 'order_state` os
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                ON osl.`id_order_state` = os.`id_order_state`
                AND osl.`id_lang` = ' . (int) Configuration::get('PS_LANG_DEFAULT') . '
             WHERE os.`deleted` = 1
             OR osl.`name` LIKE \'%cancelad%\'
             OR osl.`name` LIKE \'%reembols%\'
             OR osl.`name` LIKE \'%refund%\'
             OR osl.`name` LIKE \'%cancel%\'
             GROUP BY os.`id_order_state`'
        );
        $ids = [];
        if ($rows) {
            foreach ($rows as $row) {
                $ids[] = (int) $row['id_order_state'];
            }
        }
        return $ids;
    }

    private function getOrderByReference($referencia)
    {
        $idOrder = (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `reference` = \'' . pSQL($referencia) . '\''
        );
        if (!$idOrder) {
            return null;
        }
        return new Order($idOrder);
    }

    private function estaEnPlazo(Order $order, $dias)
    {
        $fechaEntrega = $this->getFechaEntrega($order);
        $plazo = strtotime('+' . (int) $dias . ' days', $fechaEntrega);
        return time() <= $plazo;
    }

    /**
     * Devuelve timestamp de la fecha de entrega.
     * Usa los flags shipped/delivery de order_state.
     * Si no hay historial de entrega, usa date_add del pedido como fallback
     * (el plazo empieza a contar desde entonces en el peor caso para el comercio).
     */
    private function getFechaEntrega(Order $order)
    {
        // Sin LIMIT — getRow ya devuelve solo la primera fila
        // Buscamos el primer estado con shipped=1 o delivery=1
        $sql = 'SELECT oh.`date_add`
                FROM `' . _DB_PREFIX_ . 'order_history` oh
                INNER JOIN `' . _DB_PREFIX_ . 'order_state` os
                   ON os.`id_order_state` = oh.`id_order_state`
                WHERE oh.`id_order` = ' . (int) $order->id . '
                AND (os.`shipped` = 1 OR os.`delivery` = 1)
                ORDER BY oh.`date_add` ASC';

        $row = Db::getInstance()->getRow($sql);

        if ($row && !empty($row['date_add'])) {
            return strtotime($row['date_add']);
        }

        // Fallback: usamos la fecha del pedido (conservador para el comercio)
        return strtotime($order->date_add);
    }

    private function yaExisteSolicitud($referencia)
    {
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'josra_desistimiento`
             WHERE `reference` = \'' . pSQL($referencia) . '\'
             AND `estado` != \'rechazado\''
        );
        return $count > 0;
    }

    private function enviarEmailConfirmacion($email, $nombre, $referencia, $motivo, $idDesistimiento)
    {
        $shopName = Configuration::get('PS_SHOP_NAME');
        $shopUrl  = Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__;
        $fecha    = date('d/m/Y H:i:s');

        $templateVars = [
            '{nombre}'           => $nombre,
            '{email}'            => $email,
            '{referencia}'       => $referencia,
            '{motivo}'           => $motivo,
            '{fecha}'            => $fecha,
            '{shop_name}'        => $shopName,
            '{shop_url}'         => $shopUrl,
            '{id_desistimiento}' => $idDesistimiento,
        ];

        Mail::Send(
            (int) $this->context->language->id,
            'desistimiento_confirmacion',
            $this->module->l('Confirmación de solicitud de desistimiento — ') . $shopName,
            $templateVars,
            $email,
            $nombre,
            null, null, null, null,
            _PS_MODULE_DIR_ . $this->module->name . '/mails/'
        );

        $emailCopia = Configuration::get('JOSRA_DESIST_EMAIL_COPIA');
        if ($emailCopia && Validate::isEmail($emailCopia)) {
            Mail::Send(
                (int) $this->context->language->id,
                'desistimiento_aviso_comercio',
                '[DESISTIMIENTO] Ref: ' . $referencia . ' — ' . $shopName,
                $templateVars,
                $emailCopia,
                $shopName,
                null, null, null, null,
                _PS_MODULE_DIR_ . $this->module->name . '/mails/'
            );
        }
    }
}
