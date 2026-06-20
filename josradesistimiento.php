<?php
/**
 * Módulo: josradesistimiento v1.1.0
 * Directiva (UE) 2023/2673 — Compatible PrestaShop 1.7, 8.x, 9.x
 *
 * v1.1.0:
 * - Estado de pedido "En desistimiento" creado automáticamente
 * - Pedido marcado al tramitar la solicitud
 * - Info del desistimiento visible en el backoffice del pedido
 * - Pedidos en desistimiento excluidos del desplegable
 * - Email admin mejorado con todos los datos
 * - Textos de retención generados en PHP (sin sprintf en Smarty)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Josradesistimiento extends Module
{
    const ESTADO_CONFIG_KEY = 'JOSRA_DESIST_ID_ESTADO';

    public function __construct()
    {
        $this->name          = 'josradesistimiento';
        $this->tab           = 'front_office_features';
        $this->version       = '1.1.0';
        $this->author        = 'josra';
        $this->need_instance = 0;
        $this->bootstrap     = true;

        parent::__construct();

        $this->displayName = $this->l('Botón de Desistimiento UE 2023/2673');
        $this->description = $this->l(
            'Implementa el botón de desistimiento obligatorio según la Directiva (UE) 2023/2673. ' .
            'Flujo en dos pasos, confirmación automática, estado de pedido dedicado y retención.'
        );
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    /* =========================================================
     *  INSTALACIÓN / DESINSTALACIÓN
     * ========================================================= */

    public function install()
    {
        return parent::install()
            && $this->installSql()
            && $this->installOrderState()
            && $this->installConfiguration()
            && $this->registerHooks();
    }

    public function uninstall()
    {
        // El estado de pedido NO se borra para no perder historial
        return parent::uninstall()
            && $this->uninstallSql()
            && $this->uninstallConfiguration();
    }

    private function installSql()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento` (
                `id_desistimiento`  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order`          INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `id_customer`       INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `reference`         VARCHAR(64)  NOT NULL DEFAULT \'\',
                `nombre`            VARCHAR(150) NOT NULL DEFAULT \'\',
                `email`             VARCHAR(255) NOT NULL DEFAULT \'\',
                `motivo`            VARCHAR(20)  NOT NULL DEFAULT \'\',
                `comentario`        TEXT,
                `estado`            VARCHAR(20)  NOT NULL DEFAULT \'pendiente\',
                `opcion_retencion`  VARCHAR(20)  NOT NULL DEFAULT \'\',
                `ip`                VARCHAR(45)  NOT NULL DEFAULT \'\',
                `fecha_solicitud`   DATETIME     NOT NULL,
                `fecha_procesado`   DATETIME,
                `token`             VARCHAR(64)  NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_desistimiento`),
                KEY `idx_reference` (`reference`),
                KEY `idx_customer`  (`id_customer`),
                KEY `idx_estado`    (`estado`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4
        ');
    }

    private function uninstallSql()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento`'
        );
    }

    /**
     * Crea el estado de pedido "En desistimiento" si no existe.
     * Color naranja para que destaque sin alarmar.
     */
    public function installOrderState()
    {
        // Evitar duplicados en reinstalaciones
        $idExistente = (int) Configuration::get(self::ESTADO_CONFIG_KEY);
        if ($idExistente > 0) {
            $os = new OrderState($idExistente);
            if (Validate::isLoadedObject($os)) {
                return true;
            }
        }

        $os = new OrderState();
        $os->color        = '#FF8C00';
        $os->unremovable  = false;
        $os->hidden       = false;
        $os->send_email   = false;
        $os->delivery     = false;
        $os->logable      = true;
        $os->invoice      = false;
        $os->module_name  = $this->name;

        // Nombre en todos los idiomas instalados
        $langs = Language::getLanguages(false);
        foreach ($langs as $lang) {
            $os->name[$lang['id_lang']] = 'En desistimiento';
        }

        if (!$os->add()) {
            return false;
        }

        Configuration::updateValue(self::ESTADO_CONFIG_KEY, (int) $os->id);

        // Icono (opcional, PS lo ignora si no existe)
        $iconSrc = _PS_MODULE_DIR_ . $this->name . '/views/img/estado_desistimiento.gif';
        $iconDst = _PS_IMG_DIR_ . 'os/' . (int) $os->id . '.gif';
        if (file_exists($iconSrc)) {
            copy($iconSrc, $iconDst);
        }

        return true;
    }

    private function installConfiguration()
    {
        Configuration::updateValue('JOSRA_DESIST_FOOTER',       1);
        Configuration::updateValue('JOSRA_DESIST_PRODUCT',      1);
        Configuration::updateValue('JOSRA_DESIST_ACCOUNT',      1);
        Configuration::updateValue('JOSRA_DESIST_EMAIL',        1);
        Configuration::updateValue('JOSRA_DESIST_DIAS',         14);
        Configuration::updateValue('JOSRA_DESIST_RETENCION',    1);
        Configuration::updateValue('JOSRA_DESIST_BONO_PORCENT', 10);
        Configuration::updateValue('JOSRA_DESIST_EMAIL_COPIA',  '');
        Configuration::updateValue('JOSRA_DESIST_TEXTO_BOTON',  'Desistir del contrato aquí');
        Configuration::updateValue('JOSRA_DESIST_COLOR_BOTON',  '#e74c3c');
        return true;
    }

    private function uninstallConfiguration()
    {
        foreach ([
            'JOSRA_DESIST_FOOTER', 'JOSRA_DESIST_PRODUCT', 'JOSRA_DESIST_ACCOUNT',
            'JOSRA_DESIST_EMAIL', 'JOSRA_DESIST_DIAS', 'JOSRA_DESIST_RETENCION',
            'JOSRA_DESIST_BONO_PORCENT', 'JOSRA_DESIST_EMAIL_COPIA',
            'JOSRA_DESIST_TEXTO_BOTON', 'JOSRA_DESIST_COLOR_BOTON',
            self::ESTADO_CONFIG_KEY,
        ] as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    private function registerHooks()
    {
        foreach ([
            'displayFooter',
            'displayProductAdditionalInfo',
            'displayProductButtons',
            'displayCustomerAccount',
            'displayOrderDetail',
            'displayHeader',
            'displayAdminOrderMainBottom',   // PS 1.7.7+ / 8 / 9: panel inferior del pedido en admin
            'displayAdminOrder',             // PS 1.7 legacy
        ] as $hook) {
            $this->registerHook($hook);
        }
        return true;
    }

    /* =========================================================
     *  CONFIGURACIÓN BACKOFFICE
     * ========================================================= */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submit_josra_desistimiento')) {
            $output .= $this->postProcess();
        }
        // Aseguramos que el estado existe aunque sea una actualización
        $this->installOrderState();
        $output .= $this->renderConfigForm();
        $output .= $this->renderSolicitudesTable();
        return $output;
    }

    private function postProcess()
    {
        $fields = [
            'JOSRA_DESIST_FOOTER'       => (int) Tools::getValue('JOSRA_DESIST_FOOTER'),
            'JOSRA_DESIST_PRODUCT'      => (int) Tools::getValue('JOSRA_DESIST_PRODUCT'),
            'JOSRA_DESIST_ACCOUNT'      => (int) Tools::getValue('JOSRA_DESIST_ACCOUNT'),
            'JOSRA_DESIST_EMAIL'        => (int) Tools::getValue('JOSRA_DESIST_EMAIL'),
            'JOSRA_DESIST_DIAS'         => (int) Tools::getValue('JOSRA_DESIST_DIAS'),
            'JOSRA_DESIST_RETENCION'    => (int) Tools::getValue('JOSRA_DESIST_RETENCION'),
            'JOSRA_DESIST_BONO_PORCENT' => (int) Tools::getValue('JOSRA_DESIST_BONO_PORCENT'),
            'JOSRA_DESIST_EMAIL_COPIA'  => pSQL(Tools::getValue('JOSRA_DESIST_EMAIL_COPIA')),
            'JOSRA_DESIST_TEXTO_BOTON'  => pSQL(Tools::getValue('JOSRA_DESIST_TEXTO_BOTON')),
            'JOSRA_DESIST_COLOR_BOTON'  => pSQL(Tools::getValue('JOSRA_DESIST_COLOR_BOTON')),
        ];
        foreach ($fields as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    private function renderConfigForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar  = false;
        $helper->table         = $this->table;
        $helper->module        = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'submit_josra_desistimiento';
        $helper->currentIndex  = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                'JOSRA_DESIST_FOOTER'       => Configuration::get('JOSRA_DESIST_FOOTER'),
                'JOSRA_DESIST_PRODUCT'      => Configuration::get('JOSRA_DESIST_PRODUCT'),
                'JOSRA_DESIST_ACCOUNT'      => Configuration::get('JOSRA_DESIST_ACCOUNT'),
                'JOSRA_DESIST_EMAIL'        => Configuration::get('JOSRA_DESIST_EMAIL'),
                'JOSRA_DESIST_DIAS'         => Configuration::get('JOSRA_DESIST_DIAS'),
                'JOSRA_DESIST_RETENCION'    => Configuration::get('JOSRA_DESIST_RETENCION'),
                'JOSRA_DESIST_BONO_PORCENT' => Configuration::get('JOSRA_DESIST_BONO_PORCENT'),
                'JOSRA_DESIST_EMAIL_COPIA'  => Configuration::get('JOSRA_DESIST_EMAIL_COPIA'),
                'JOSRA_DESIST_TEXTO_BOTON'  => Configuration::get('JOSRA_DESIST_TEXTO_BOTON'),
                'JOSRA_DESIST_COLOR_BOTON'  => Configuration::get('JOSRA_DESIST_COLOR_BOTON'),
            ],
            'languages'   => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $sw = [
            ['id' => 'active_on',  'value' => 1, 'label' => $this->l('Sí')],
            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración — Botón de Desistimiento UE 2023/2673'),
                    'icon'  => 'icon-cogs',
                ],
                'tabs' => [
                    'visibilidad' => $this->l('Visibilidad'),
                    'retencion'   => $this->l('Retención'),
                    'textos'      => $this->l('Textos y Diseño'),
                    'notif'       => $this->l('Notificaciones'),
                ],
                'input' => [
                    // ---- VISIBILIDAD ----
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Mostrar en footer'),
                        'name'   => 'JOSRA_DESIST_FOOTER',
                        'tab'    => 'visibilidad',
                        'values' => $sw,
                        'desc'   => $this->l('Botón siempre visible en el pie de página de la tienda.'),
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Mostrar en ficha de producto'),
                        'name'   => 'JOSRA_DESIST_PRODUCT',
                        'tab'    => 'visibilidad',
                        'values' => $sw,
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Mostrar en área de cliente / Mis pedidos'),
                        'name'   => 'JOSRA_DESIST_ACCOUNT',
                        'tab'    => 'visibilidad',
                        'values' => $sw,
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->l('Días de desistimiento'),
                        'name'   => 'JOSRA_DESIST_DIAS',
                        'tab'    => 'visibilidad',
                        'class'  => 'fixed-width-sm',
                        'suffix' => $this->l('días'),
                        'desc'   => $this->l('La Directiva establece 14 días naturales desde la entrega. No reducir sin asesoría legal.'),
                    ],
                    // ---- RETENCIÓN ----
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Activar estrategia de retención'),
                        'name'   => 'JOSRA_DESIST_RETENCION',
                        'tab'    => 'retencion',
                        'values' => $sw,
                        'desc'   => $this->l(
                            'Muestra al cliente opciones de saldo en tienda o cambio antes de confirmar el desistimiento. ' .
                            '100% legal: el cliente siempre puede ignorarlo y continuar con el desistimiento.'
                        ),
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->l('% de bonificación sobre saldo en tienda'),
                        'name'   => 'JOSRA_DESIST_BONO_PORCENT',
                        'tab'    => 'retencion',
                        'class'  => 'fixed-width-sm',
                        'suffix' => '%',
                        'desc'   => $this->l(
                            'Ej: 10 → el cliente recibe 110€ de saldo por cada 100€ devueltos. ' .
                            'El dinero se queda en la tienda. Poner 0 para no ofrecer bonificación.'
                        ),
                    ],
                    // ---- TEXTOS Y DISEÑO ----
                    [
                        'type'  => 'text',
                        'label' => $this->l('Texto del botón'),
                        'name'  => 'JOSRA_DESIST_TEXTO_BOTON',
                        'tab'   => 'textos',
                        'desc'  => $this->l(
                            'La Directiva propone "Desistir del contrato aquí" o equivalente inequívoco. ' .
                            'Evitar etiquetas vagas como "Contáctanos" o "Gestionar pedido".'
                        ),
                    ],
                    [
                        'type'  => 'color',
                        'label' => $this->l('Color del botón'),
                        'name'  => 'JOSRA_DESIST_COLOR_BOTON',
                        'tab'   => 'textos',
                    ],
                    // ---- NOTIFICACIONES ----
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Enviar email de confirmación al cliente'),
                        'name'   => 'JOSRA_DESIST_EMAIL',
                        'tab'    => 'notif',
                        'values' => $sw,
                        'desc'   => $this->l('Obligatorio por la Directiva: acuse de recibo inmediato con fecha y hora.'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Email de copia para el comercio (CC)'),
                        'name'  => 'JOSRA_DESIST_EMAIL_COPIA',
                        'tab'   => 'notif',
                        'desc'  => $this->l('Dejar en blanco para no recibir copia. El pedido se marca automáticamente como "En desistimiento".'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar configuración'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ]]);
    }

    private function renderSolicitudesTable()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento` ORDER BY `fecha_solicitud` DESC LIMIT 50'
        );

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> '
            . $this->l('Últimas 50 solicitudes de desistimiento') . '</div>';

        if (empty($rows)) {
            $html .= '<p class="alert alert-info">' . $this->l('No hay solicitudes todavía.') . '</p>';
        } else {
            $badges = [
                'pendiente' => '<span class="label label-warning">Pendiente</span>',
                'procesado' => '<span class="label label-success">Procesado</span>',
                'rechazado' => '<span class="label label-danger">Rechazado</span>',
                'retenido'  => '<span class="label label-info">Retenido</span>',
            ];
            $html .= '<table class="table tableDnD"><thead><tr>
                <th>ID</th><th>Referencia</th><th>Cliente</th><th>Email</th>
                <th>Motivo</th><th>Estado</th><th>Retención</th><th>Fecha</th>
            </tr></thead><tbody>';
            foreach ($rows as $r) {
                $badge = isset($badges[$r['estado']]) ? $badges[$r['estado']] : $r['estado'];
                $html .= '<tr>
                    <td>' . (int)$r['id_desistimiento'] . '</td>
                    <td><strong>' . htmlspecialchars($r['reference']) . '</strong></td>
                    <td>' . htmlspecialchars($r['nombre']) . '</td>
                    <td>' . htmlspecialchars($r['email']) . '</td>
                    <td>' . htmlspecialchars($r['motivo']) . '</td>
                    <td>' . $badge . '</td>
                    <td>' . htmlspecialchars($r['opcion_retencion']) . '</td>
                    <td>' . htmlspecialchars($r['fecha_solicitud']) . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>';
        return $html;
    }

    /* =========================================================
     *  HOOKS FRONT
     * ========================================================= */

    public function hookDisplayHeader($params)
    {
        $this->context->controller->addCSS($this->_path . 'views/css/josradesistimiento.css');
        $this->context->controller->addJS($this->_path . 'views/js/josradesistimiento.js');
    }

    public function hookDisplayFooter($params)
    {
        if (!Configuration::get('JOSRA_DESIST_FOOTER')) return '';
        return $this->renderBoton('footer');
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!Configuration::get('JOSRA_DESIST_PRODUCT')) return '';
        return $this->renderBoton('producto');
    }

    public function hookDisplayProductButtons($params)
    {
        if (!Configuration::get('JOSRA_DESIST_PRODUCT')) return '';
        return $this->renderBoton('producto');
    }

    public function hookDisplayCustomerAccount($params)
    {
        if (!Configuration::get('JOSRA_DESIST_ACCOUNT')) return '';
        return $this->renderBoton('cuenta');
    }

    public function hookDisplayOrderDetail($params)
    {
        if (!Configuration::get('JOSRA_DESIST_ACCOUNT')) return '';
        $order = isset($params['order']) ? $params['order'] : null;
        return $this->renderBoton('pedido', $order);
    }

    /* =========================================================
     *  HOOKS BACKOFFICE — panel info en pedido
     * ========================================================= */

    public function hookDisplayAdminOrderMainBottom($params)
    {
        return $this->renderAdminOrderPanel($params);
    }

    public function hookDisplayAdminOrder($params)
    {
        // Solo se ejecuta si NO existe hookDisplayAdminOrderMainBottom (PS < 1.7.7)
        // Para evitar duplicado en versiones modernas comprobamos si ya se renderizó
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return '';
        }
        return $this->renderAdminOrderPanel($params);
    }

    private function renderAdminOrderPanel($params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!$idOrder) {
            return '';
        }

        $solicitud = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento`
             WHERE `id_order` = ' . $idOrder . '
             ORDER BY `fecha_solicitud` DESC'
        );

        if (!$solicitud) {
            return '';
        }

        $estadoConfig = [
            'pendiente' => ['label' => 'Pendiente',  'color' => '#f39c12', 'bg' => '#fef9e7', 'icon' => '⏳'],
            'procesado' => ['label' => 'Procesado',  'color' => '#27ae60', 'bg' => '#eafaf1', 'icon' => '✅'],
            'rechazado' => ['label' => 'Rechazado',  'color' => '#e74c3c', 'bg' => '#fdedec', 'icon' => '❌'],
            'retenido'  => ['label' => 'Retenido',   'color' => '#2980b9', 'bg' => '#eaf4fb', 'icon' => '🔄'],
        ];
        $est = isset($estadoConfig[$solicitud['estado']])
            ? $estadoConfig[$solicitud['estado']]
            : ['label' => $solicitud['estado'], 'color' => '#666', 'bg' => '#f5f5f5', 'icon' => '•'];

        $opcionLabels = [
            'saldo'     => '💳 Saldo en tienda con bonificación',
            'cambio'    => '🔄 Cambio de producto',
            'continuar' => '↩ Continuar con reembolso',
            ''          => '—',
        ];
        $opcionLabel = isset($opcionLabels[$solicitud['opcion_retencion']])
            ? $opcionLabels[$solicitud['opcion_retencion']]
            : htmlspecialchars($solicitud['opcion_retencion']);

        $motivoLabels = [
            'arrepentimiento' => 'Me arrepentí de la compra',
            'talla_color'     => 'Talla / color incorrecto',
            'no_esperado'     => 'El producto no era lo esperado',
            'retraso'         => 'Tardó demasiado en llegar',
            'defecto'         => 'El producto llegó con defecto',
            'otro'            => 'Otro motivo',
        ];
        $motivoLabel = isset($motivoLabels[$solicitud['motivo']])
            ? $motivoLabels[$solicitud['motivo']]
            : htmlspecialchars($solicitud['motivo']);

        $html = '
        <div class="panel" id="josra-panel-desistimiento" style="border:none;box-shadow:0 1px 3px rgba(0,0,0,0.15);border-radius:6px;overflow:hidden;margin-bottom:20px;">

            <!-- CABECERA -->
            <div style="background:linear-gradient(135deg,#2c3e50 0%,#34495e 100%);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:20px;">↩</span>
                    <span style="color:#fff;font-size:15px;font-weight:600;">' . $this->l('Solicitud de Desistimiento') . '</span>
                    <span style="font-size:12px;color:#bdc3c7;">#JOSRA-' . (int)$solicitud['id_desistimiento'] . '</span>
                </div>
                <span style="background:' . $est['color'] . ';color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;">
                    ' . $est['icon'] . ' ' . $est['label'] . '
                </span>
            </div>

            <!-- CUERPO -->
            <div style="background:' . $est['bg'] . ';padding:0;">
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="width:50%;padding:0;vertical-align:top;">
                            <table style="width:100%;border-collapse:collapse;">
                                <tr>
                                    <td colspan="2" style="padding:10px 20px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;">
                                        ' . $this->l('Datos del cliente') . '
                                    </td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(0,0,0,0.06);">
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;width:45%;">' . $this->l('Cliente') . '</td>
                                    <td style="padding:8px 20px;font-size:13px;font-weight:500;">' . htmlspecialchars($solicitud['nombre']) . '</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(0,0,0,0.06);">
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;">' . $this->l('Email') . '</td>
                                    <td style="padding:8px 20px;font-size:13px;">
                                        <a href="mailto:' . htmlspecialchars($solicitud['email']) . '" style="color:#2980b9;">
                                            ' . htmlspecialchars($solicitud['email']) . '
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;">' . $this->l('IP') . '</td>
                                    <td style="padding:8px 20px;font-size:12px;color:#95a5a6;">' . htmlspecialchars($solicitud['ip']) . '</td>
                                </tr>
                            </table>
                        </td>
                        <td style="width:50%;padding:0;vertical-align:top;border-left:1px solid rgba(0,0,0,0.06);">
                            <table style="width:100%;border-collapse:collapse;">
                                <tr>
                                    <td colspan="2" style="padding:10px 20px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;">
                                        ' . $this->l('Detalles solicitud') . '
                                    </td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(0,0,0,0.06);">
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;width:45%;">' . $this->l('Motivo') . '</td>
                                    <td style="padding:8px 20px;font-size:13px;font-weight:500;">' . $motivoLabel . '</td>
                                </tr>
                                <tr style="border-bottom:1px solid rgba(0,0,0,0.06);">
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;">' . $this->l('Decisión cliente') . '</td>
                                    <td style="padding:8px 20px;font-size:13px;font-weight:600;color:' . $est['color'] . ';">' . $opcionLabel . '</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 20px;color:#7f8c8d;font-size:12px;">' . $this->l('Fecha solicitud') . '</td>
                                    <td style="padding:8px 20px;font-size:12px;">' . htmlspecialchars($solicitud['fecha_solicitud']) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>';

        if (!empty($solicitud['comentario'])) {
            $html .= '
                    <tr>
                        <td colspan="2" style="padding:10px 20px;border-top:1px solid rgba(0,0,0,0.06);">
                            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#95a5a6;">' . $this->l('Comentario del cliente') . '</span><br>
                            <span style="font-size:13px;color:#555;font-style:italic;margin-top:4px;display:block;">"' . htmlspecialchars($solicitud['comentario']) . '"</span>
                        </td>
                    </tr>';
        }

        $html .= '
                </table>
            </div>

            <!-- PIE -->
            <div style="background:#f8f9fa;border-top:1px solid #e0e0e0;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:11px;color:#95a5a6;">
                    ' . $this->l('Directiva (UE) 2023/2673 — josradesistimiento v') . $this->version . '
                </span>
                <a href="' . $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name . '"
                   style="font-size:11px;color:#2980b9;text-decoration:none;">
                    ' . $this->l('Ver todas las solicitudes →') . '
                </a>
            </div>

        </div>';

        return $html;
    }

    /* =========================================================
     *  RENDERIZADO DEL BOTÓN FRONT
     * ========================================================= */

    private function renderBoton($contexto = 'footer', $order = null)
    {
        $textoBoton = Configuration::get('JOSRA_DESIST_TEXTO_BOTON') ?: 'Desistir del contrato aquí';
        $colorBoton = Configuration::get('JOSRA_DESIST_COLOR_BOTON') ?: '#e74c3c';
        $dias       = (int) Configuration::get('JOSRA_DESIST_DIAS') ?: 14;
        $retencion  = (int) Configuration::get('JOSRA_DESIST_RETENCION');
        $bonoPct    = (int) Configuration::get('JOSRA_DESIST_BONO_PORCENT');

        $url = $this->context->link->getModuleLink($this->name, 'desistir', [], true);
        $referencia = ($order && isset($order->reference)) ? $order->reference : '';

        $this->context->smarty->assign([
            'josra_url_desistir' => $url,
            'josra_texto_boton'  => $textoBoton,
            'josra_color_boton'  => $colorBoton,
            'josra_contexto'     => $contexto,
            'josra_dias'         => $dias,
            'josra_retencion'    => $retencion,
            'josra_bono_pct'     => $bonoPct,
            'josra_referencia'   => $referencia,
        ]);

        return $this->display(__FILE__, 'views/templates/front/boton.tpl');
    }

    /* =========================================================
     *  MÉTODO PÚBLICO: marcar pedido como "En desistimiento"
     *  Llamado desde el controlador front al procesar la solicitud
     * ========================================================= */

    public function marcarPedidoEnDesistimiento(Order $order, $idDesistimiento)
    {
        $idEstado = (int) Configuration::get(self::ESTADO_CONFIG_KEY);
        if (!$idEstado || !Validate::isLoadedObject($order)) {
            return false;
        }

        // Solo cambiar estado si no está ya en desistimiento, cancelado o reembolsado
        if ((int) $order->current_state === $idEstado) {
            return true;
        }

        $history = new OrderHistory();
        $history->id_order      = (int) $order->id;
        $history->id_order_state = $idEstado;
        $history->id_employee   = 0;
        $history->changeIdOrderState($idEstado, $order);
        $history->add();

        return true;
    }
}
