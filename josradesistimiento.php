<?php
/**
 * Módulo: josradesistimiento v1.2.0
 * Directiva (UE) 2023/2673 — Compatible PrestaShop 1.7, 8.x, 9.x
 *
 * v1.1.0:
 * - Estado de pedido "En desistimiento" creado automáticamente
 * - Pedido marcado al tramitar la solicitud
 * - Info del desistimiento visible en el backoffice del pedido
 * - Pedidos en desistimiento excluidos del desplegable
 * - Email admin mejorado con todos los datos
 * - Textos de retención generados en PHP (sin sprintf en Smarty)
 *
 * v1.2.0:
 * - Configuración legal ampliada: quién paga los gastos de devolución,
 *   dirección de devolución, texto/URL de la política, remitente y
 *   reply-to de los correos de notificación
 * - Motivos de desistimiento editables por idioma desde el backoffice
 * - "Otro motivo" puede exigir un detalle obligatorio al cliente
 *
 * v1.3.0:
 * - Corrige el "Otro motivo": ahora es una entrada fija del sistema
 *   (clave "otro" inmutable, solo el texto es editable), por lo que
 *   "exigir detalle" funciona siempre, sin depender del formato que
 *   use el admin al editar la lista de motivos
 * - Filtros y exenciones: exclusión de productos/categorías/fabricantes/
 *   proveedores del derecho de desistimiento o ampliación de su plazo,
 *   filtros por transportista y estado de pedido, y exclusión B2B
 *   (por grupo de cliente o por empresa indicada en el pedido)
 * - Gestión de solicitudes en backoffice: KPIs, aprobar/rechazar con
 *   motivo, marcar recibido/reembolsado, notas internas y cierre
 *   automático al reembolsar el pedido
 * - Acuse de recibo en PDF descargable adjunto al email, y tarea cron
 *   que expira solicitudes vencidas y envía recordatorios de SLA
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
        $this->version       = '1.3.0';
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
        $ok = Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento` (
                `id_desistimiento`     INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order`             INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `id_customer`          INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `reference`            VARCHAR(64)  NOT NULL DEFAULT \'\',
                `nombre`               VARCHAR(150) NOT NULL DEFAULT \'\',
                `email`                VARCHAR(255) NOT NULL DEFAULT \'\',
                `motivo`               VARCHAR(20)  NOT NULL DEFAULT \'\',
                `comentario`           TEXT,
                `estado`               VARCHAR(20)  NOT NULL DEFAULT \'pendiente\',
                `opcion_retencion`     VARCHAR(20)  NOT NULL DEFAULT \'\',
                `ip`                   VARCHAR(45)  NOT NULL DEFAULT \'\',
                `fecha_solicitud`      DATETIME     NOT NULL,
                `fecha_procesado`      DATETIME,
                `fecha_limite`         DATETIME     NULL,
                `motivo_rechazo`       TEXT,
                `notas_internas`       TEXT,
                `bienes_recibidos`     TINYINT(1)   NOT NULL DEFAULT 0,
                `reembolsado`          TINYINT(1)   NOT NULL DEFAULT 0,
                `recordatorio_enviado` TINYINT(1)   NOT NULL DEFAULT 0,
                `fecha_aprobado`       DATETIME     NULL,
                `fecha_rechazado`      DATETIME     NULL,
                `fecha_recibido`       DATETIME     NULL,
                `fecha_reembolsado`    DATETIME     NULL,
                `auditoria`            TEXT,
                `token`                VARCHAR(64)  NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_desistimiento`),
                KEY `idx_reference` (`reference`),
                KEY `idx_customer`  (`id_customer`),
                KEY `idx_estado`    (`estado`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4
        ');

        $ok = $ok && Db::getInstance()->execute('
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
        ');

        return $ok;
    }

    private function uninstallSql()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento`'
        ) && Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'josra_desistimiento_exclusion`'
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

        // ---- Configuración legal ampliada (v1.2.0) ----
        Configuration::updateValue('JOSRA_DESIST_GASTOS_DEVOLUCION', 'cliente');
        Configuration::updateValue('JOSRA_DESIST_DIRECCION_DEVOLUCION', '');
        Configuration::updateValue('JOSRA_DESIST_POLITICA_URL', '');
        Configuration::updateValue('JOSRA_DESIST_EMAIL_REMITENTE', '');
        Configuration::updateValue('JOSRA_DESIST_EMAIL_REPLYTO', '');
        Configuration::updateValue('JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO', 0);

        // ---- Filtros y exenciones (v1.2.1) ----
        Configuration::updateValue('JOSRA_DESIST_CARRIERS_EXCLUIDOS', '');
        Configuration::updateValue('JOSRA_DESIST_ESTADOS_EXCLUIDOS', '');
        Configuration::updateValue('JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS', '');
        Configuration::updateValue('JOSRA_DESIST_EXCLUIR_SI_EMPRESA', 0);
        Configuration::updateValue('JOSRA_DESIST_CRON_TOKEN', Tools::passwdGen(32));
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            Configuration::updateValue('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang, '', true);
            Configuration::updateValue('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang, $this->l('Otro motivo'), true);
            Configuration::updateValue(
                'JOSRA_DESIST_MOTIVOS_' . $idLang,
                $this->serializarMotivos($this->getMotivosPorDefecto()),
                true
            );
        }

        return true;
    }

    private function uninstallConfiguration()
    {
        foreach ([
            'JOSRA_DESIST_FOOTER', 'JOSRA_DESIST_PRODUCT', 'JOSRA_DESIST_ACCOUNT',
            'JOSRA_DESIST_EMAIL', 'JOSRA_DESIST_DIAS', 'JOSRA_DESIST_RETENCION',
            'JOSRA_DESIST_BONO_PORCENT', 'JOSRA_DESIST_EMAIL_COPIA',
            'JOSRA_DESIST_TEXTO_BOTON', 'JOSRA_DESIST_COLOR_BOTON',
            'JOSRA_DESIST_GASTOS_DEVOLUCION', 'JOSRA_DESIST_DIRECCION_DEVOLUCION',
            'JOSRA_DESIST_POLITICA_URL', 'JOSRA_DESIST_EMAIL_REMITENTE',
            'JOSRA_DESIST_EMAIL_REPLYTO', 'JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO',
            'JOSRA_DESIST_CARRIERS_EXCLUIDOS', 'JOSRA_DESIST_ESTADOS_EXCLUIDOS',
            'JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS', 'JOSRA_DESIST_EXCLUIR_SI_EMPRESA',
            'JOSRA_DESIST_CRON_TOKEN',
            self::ESTADO_CONFIG_KEY,
        ] as $key) {
            Configuration::deleteByName($key);
        }
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            Configuration::deleteByName('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang);
            Configuration::deleteByName('JOSRA_DESIST_MOTIVOS_' . $idLang);
            Configuration::deleteByName('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang);
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
            'actionOrderStatusPostUpdate',   // cierre automático al reembolsar el pedido
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
        if (Tools::isSubmit('submit_josra_exclusion_add')) {
            $output .= $this->postProcessExclusionAdd();
        }
        if (Tools::isSubmit('josra_exclusion_delete')) {
            $output .= $this->postProcessExclusionDelete();
        }
        if (Tools::isSubmit('josra_accion')) {
            $output .= $this->postProcessAccionSolicitud();
        }
        if (Tools::isSubmit('submit_josra_regenerar_token')) {
            Configuration::updateValue('JOSRA_DESIST_CRON_TOKEN', Tools::passwdGen(32));
            $output .= $this->displayConfirmation($this->l('Token de la tarea cron regenerado. Actualiza la URL en tu planificador.'));
        }

        // Aseguramos que el estado existe aunque sea una actualización
        $this->installOrderState();

        $output .= $this->renderConfigForm();
        $output .= $this->renderExclusionesPanel();
        $output .= $this->renderCronPanel();
        $output .= $this->renderKpiPanel();
        $output .= $this->renderSolicitudesTable();
        return $output;
    }

    private function postProcess()
    {
        $fields = [
            'JOSRA_DESIST_FOOTER'                 => (int) Tools::getValue('JOSRA_DESIST_FOOTER'),
            'JOSRA_DESIST_PRODUCT'                => (int) Tools::getValue('JOSRA_DESIST_PRODUCT'),
            'JOSRA_DESIST_ACCOUNT'                => (int) Tools::getValue('JOSRA_DESIST_ACCOUNT'),
            'JOSRA_DESIST_EMAIL'                   => (int) Tools::getValue('JOSRA_DESIST_EMAIL'),
            'JOSRA_DESIST_DIAS'                    => (int) Tools::getValue('JOSRA_DESIST_DIAS'),
            'JOSRA_DESIST_RETENCION'               => (int) Tools::getValue('JOSRA_DESIST_RETENCION'),
            'JOSRA_DESIST_BONO_PORCENT'            => (int) Tools::getValue('JOSRA_DESIST_BONO_PORCENT'),
            'JOSRA_DESIST_EMAIL_COPIA'             => pSQL(Tools::getValue('JOSRA_DESIST_EMAIL_COPIA')),
            'JOSRA_DESIST_TEXTO_BOTON'             => pSQL(Tools::getValue('JOSRA_DESIST_TEXTO_BOTON')),
            'JOSRA_DESIST_COLOR_BOTON'             => pSQL(Tools::getValue('JOSRA_DESIST_COLOR_BOTON')),
            'JOSRA_DESIST_GASTOS_DEVOLUCION'       => in_array(Tools::getValue('JOSRA_DESIST_GASTOS_DEVOLUCION'), ['cliente', 'comercio'], true)
                ? Tools::getValue('JOSRA_DESIST_GASTOS_DEVOLUCION') : 'cliente',
            'JOSRA_DESIST_DIRECCION_DEVOLUCION'    => pSQL(Tools::getValue('JOSRA_DESIST_DIRECCION_DEVOLUCION'), true),
            'JOSRA_DESIST_POLITICA_URL'            => pSQL(Tools::getValue('JOSRA_DESIST_POLITICA_URL')),
            'JOSRA_DESIST_EMAIL_REMITENTE'         => pSQL(Tools::getValue('JOSRA_DESIST_EMAIL_REMITENTE')),
            'JOSRA_DESIST_EMAIL_REPLYTO'           => pSQL(Tools::getValue('JOSRA_DESIST_EMAIL_REPLYTO')),
            'JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO' => (int) Tools::getValue('JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO'),
            'JOSRA_DESIST_CARRIERS_EXCLUIDOS'      => implode(',', array_filter(array_map('intval', (array) Tools::getValue('JOSRA_DESIST_CARRIERS_EXCLUIDOS', [])))),
            'JOSRA_DESIST_ESTADOS_EXCLUIDOS'       => implode(',', array_filter(array_map('intval', (array) Tools::getValue('JOSRA_DESIST_ESTADOS_EXCLUIDOS', [])))),
            'JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS'    => implode(',', array_filter(array_map('intval', (array) Tools::getValue('JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS', [])))),
            'JOSRA_DESIST_EXCLUIR_SI_EMPRESA'      => (int) Tools::getValue('JOSRA_DESIST_EXCLUIR_SI_EMPRESA'),
        ];
        foreach ($fields as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        // Campos multi-idioma: política de desistimiento y lista de motivos
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            Configuration::updateValue(
                'JOSRA_DESIST_POLITICA_TEXTO_' . $idLang,
                pSQL(Tools::getValue('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang), true),
                true
            );
            $lineas = $this->parsearMotivosTextarea(Tools::getValue('JOSRA_DESIST_MOTIVOS_' . $idLang, ''));
            unset($lineas['otro']);
            if (empty($lineas)) {
                $lineas = $this->getMotivosPorDefecto();
            }
            Configuration::updateValue(
                'JOSRA_DESIST_MOTIVOS_' . $idLang,
                $this->serializarMotivos($lineas),
                true
            );
            Configuration::updateValue(
                'JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang,
                pSQL(Tools::getValue('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . $idLang)) ?: $this->l('Otro motivo'),
                true
            );
        }

        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    /* =========================================================
     *  MOTIVOS DE DESISTIMIENTO (editables por idioma)
     * ========================================================= */

    /**
     * Lista de motivos "normales" por defecto (sin contar "Otro motivo",
     * que siempre se gestiona como entrada fija del sistema — ver getMotivoOtroLabel()).
     */
    public function getMotivosPorDefecto()
    {
        return [
            'arrepentimiento' => $this->l('Me arrepentí de la compra'),
            'talla_color'     => $this->l('Talla / color incorrecto'),
            'no_esperado'     => $this->l('El producto no era lo esperado'),
            'retraso'         => $this->l('Tardó demasiado en llegar'),
            'defecto'         => $this->l('El producto llegó con defecto'),
        ];
    }

    /**
     * Etiqueta de "Otro motivo" para un idioma. Su clave ('otro') es fija
     * para que la opción "exigir detalle" pueda reconocerla siempre,
     * independientemente de cómo el admin redacte el resto de la lista.
     */
    public function getMotivoOtroLabel($idLang = null)
    {
        $idLang = $idLang ?: (int) $this->context->language->id;
        $label = Configuration::get('JOSRA_DESIST_MOTIVO_OTRO_LABEL_' . (int) $idLang);
        return $label !== false && $label !== '' ? $label : $this->l('Otro motivo');
    }

    /**
     * Devuelve la lista de motivos (key => etiqueta traducida) para un idioma,
     * con "Otro motivo" siempre al final con clave fija "otro".
     * Lee la configuración editable del admin; si está vacía, usa el listado por defecto.
     */
    public function getMotivos($idLang = null)
    {
        $idLang = $idLang ?: (int) $this->context->language->id;
        $raw = Configuration::get('JOSRA_DESIST_MOTIVOS_' . (int) $idLang);
        $motivos = $this->deserializarMotivos($raw);
        unset($motivos['otro']);

        if (empty($motivos)) {
            $motivos = $this->getMotivosPorDefecto();
        }
        $motivos['otro'] = $this->getMotivoOtroLabel($idLang);
        return $motivos;
    }

    /**
     * Convierte el textarea del admin ("clave|Etiqueta" por línea) en un array clave => etiqueta.
     * La clave se normaliza a un slug corto (encaja en la columna `motivo` VARCHAR(20)).
     * La clave "otro" está reservada al sistema y se ignora aquí si aparece.
     */
    private function parsearMotivosTextarea($texto)
    {
        $motivos = [];
        $lineas = preg_split('/\r\n|\r|\n/', (string) $texto);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }
            if (strpos($linea, '|') !== false) {
                [$clave, $etiqueta] = array_map('trim', explode('|', $linea, 2));
            } else {
                $clave   = $linea;
                $etiqueta = $linea;
            }
            $clave = Tools::substr(Tools::str2url($clave), 0, 20);
            if ($clave === '' || $clave === 'otro') {
                continue;
            }
            $motivos[$clave] = $etiqueta;
        }
        return $motivos;
    }

    public function serializarMotivos(array $motivos)
    {
        $lineas = [];
        foreach ($motivos as $clave => $etiqueta) {
            $lineas[] = $clave . '|' . $etiqueta;
        }
        return implode("\n", $lineas);
    }

    private function deserializarMotivos($raw)
    {
        if (empty($raw)) {
            return [];
        }
        return $this->parsearMotivosTextarea($raw);
    }

    /**
     * Convierte una lista de ids separados por coma guardada en Configuration
     * en un array asociativo [id => 1] para marcar checkboxes en HelperForm.
     */
    public function idsConfigComoArrayMarcado($configKey)
    {
        $marcados = [];
        $raw = (string) Configuration::get($configKey);
        foreach (array_filter(array_map('intval', explode(',', $raw))) as $id) {
            $marcados[$id] = 1;
        }
        return $marcados;
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
        $motivosPorIdioma = [];
        $politicaPorIdioma = [];
        $motivoOtroPorIdioma = [];
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $motivosCustom = $this->getMotivos($idLang);
            unset($motivosCustom['otro']);
            $motivosPorIdioma[$idLang]    = $this->serializarMotivos($motivosCustom);
            $politicaPorIdioma[$idLang]   = Configuration::get('JOSRA_DESIST_POLITICA_TEXTO_' . $idLang);
            $motivoOtroPorIdioma[$idLang] = $this->getMotivoOtroLabel($idLang);
        }

        $helper->tpl_vars = [
            'fields_value' => [
                'JOSRA_DESIST_FOOTER'                 => Configuration::get('JOSRA_DESIST_FOOTER'),
                'JOSRA_DESIST_PRODUCT'                => Configuration::get('JOSRA_DESIST_PRODUCT'),
                'JOSRA_DESIST_ACCOUNT'                => Configuration::get('JOSRA_DESIST_ACCOUNT'),
                'JOSRA_DESIST_EMAIL'                  => Configuration::get('JOSRA_DESIST_EMAIL'),
                'JOSRA_DESIST_DIAS'                   => Configuration::get('JOSRA_DESIST_DIAS'),
                'JOSRA_DESIST_RETENCION'              => Configuration::get('JOSRA_DESIST_RETENCION'),
                'JOSRA_DESIST_BONO_PORCENT'           => Configuration::get('JOSRA_DESIST_BONO_PORCENT'),
                'JOSRA_DESIST_EMAIL_COPIA'            => Configuration::get('JOSRA_DESIST_EMAIL_COPIA'),
                'JOSRA_DESIST_TEXTO_BOTON'            => Configuration::get('JOSRA_DESIST_TEXTO_BOTON'),
                'JOSRA_DESIST_COLOR_BOTON'            => Configuration::get('JOSRA_DESIST_COLOR_BOTON'),
                'JOSRA_DESIST_GASTOS_DEVOLUCION'      => Configuration::get('JOSRA_DESIST_GASTOS_DEVOLUCION'),
                'JOSRA_DESIST_DIRECCION_DEVOLUCION'   => Configuration::get('JOSRA_DESIST_DIRECCION_DEVOLUCION'),
                'JOSRA_DESIST_POLITICA_URL'           => Configuration::get('JOSRA_DESIST_POLITICA_URL'),
                'JOSRA_DESIST_EMAIL_REMITENTE'        => Configuration::get('JOSRA_DESIST_EMAIL_REMITENTE'),
                'JOSRA_DESIST_EMAIL_REPLYTO'          => Configuration::get('JOSRA_DESIST_EMAIL_REPLYTO'),
                'JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO' => Configuration::get('JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO'),
                'JOSRA_DESIST_POLITICA_TEXTO'         => $politicaPorIdioma,
                'JOSRA_DESIST_MOTIVOS'                => $motivosPorIdioma,
                'JOSRA_DESIST_MOTIVO_OTRO_LABEL'      => $motivoOtroPorIdioma,
                'JOSRA_DESIST_CARRIERS_EXCLUIDOS'     => $this->idsConfigComoArrayMarcado('JOSRA_DESIST_CARRIERS_EXCLUIDOS'),
                'JOSRA_DESIST_ESTADOS_EXCLUIDOS'      => $this->idsConfigComoArrayMarcado('JOSRA_DESIST_ESTADOS_EXCLUIDOS'),
                'JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS'   => $this->idsConfigComoArrayMarcado('JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS'),
                'JOSRA_DESIST_EXCLUIR_SI_EMPRESA'     => Configuration::get('JOSRA_DESIST_EXCLUIR_SI_EMPRESA'),
            ],
            'languages'   => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $sw = [
            ['id' => 'active_on',  'value' => 1, 'label' => $this->l('Sí')],
            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
        ];

        $idLangActual = (int) $this->context->language->id;

        $carriersQuery = [];
        foreach (Carrier::getCarriers($idLangActual, true) as $carrier) {
            $carriersQuery[] = ['id_carrier' => (int) $carrier['id_carrier'], 'name' => $carrier['name']];
        }

        $orderStatesQuery = [];
        foreach (OrderState::getOrderStates($idLangActual) as $orderState) {
            $orderStatesQuery[] = ['id_order_state' => (int) $orderState['id_order_state'], 'name' => $orderState['name']];
        }

        $groupsQuery = [];
        foreach (Group::getGroups($idLangActual) as $group) {
            $groupsQuery[] = ['id_group' => (int) $group['id_group'], 'name' => $group['name']];
        }

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración — Botón de Desistimiento UE 2023/2673'),
                    'icon'  => 'icon-cogs',
                ],
                'tabs' => [
                    'visibilidad' => $this->l('Visibilidad'),
                    'legal'       => $this->l('Devoluciones y Política'),
                    'motivos'     => $this->l('Motivos de desistimiento'),
                    'retencion'   => $this->l('Retención'),
                    'textos'      => $this->l('Textos y Diseño'),
                    'notif'       => $this->l('Notificaciones'),
                    'filtros'     => $this->l('Filtros y Exenciones'),
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
                    // ---- DEVOLUCIONES Y POLÍTICA ----
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Quién paga los gastos de devolución'),
                        'name'    => 'JOSRA_DESIST_GASTOS_DEVOLUCION',
                        'tab'     => 'legal',
                        'options' => [
                            'query' => [
                                ['id' => 'cliente',  'name' => $this->l('El cliente')],
                                ['id' => 'comercio', 'name' => $this->l('El comercio')],
                            ],
                            'id'   => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Por defecto, salvo que se indique lo contrario, los gastos de devolución corren a cargo del cliente.'),
                    ],
                    [
                        'type'  => 'textarea',
                        'label' => $this->l('Dirección de devolución mostrada al cliente'),
                        'name'  => 'JOSRA_DESIST_DIRECCION_DEVOLUCION',
                        'tab'   => 'legal',
                        'rows'  => 3,
                        'desc'  => $this->l('Dirección a la que el cliente debe enviar el producto. Se muestra en el formulario y en el email de confirmación.'),
                    ],
                    [
                        'type'  => 'textarea',
                        'label' => $this->l('Texto de la política de desistimiento'),
                        'name'  => 'JOSRA_DESIST_POLITICA_TEXTO',
                        'tab'   => 'legal',
                        'lang'  => true,
                        'rows'  => 5,
                        'desc'  => $this->l('Texto legal mostrado en el formulario. Déjalo vacío si prefieres enlazar a una URL externa.'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('URL de la política de desistimiento'),
                        'name'  => 'JOSRA_DESIST_POLITICA_URL',
                        'tab'   => 'legal',
                        'desc'  => $this->l('Ej: enlace a una página CMS con las condiciones completas. Opcional si ya has rellenado el texto.'),
                    ],
                    // ---- MOTIVOS DE DESISTIMIENTO ----
                    [
                        'type'  => 'textarea',
                        'label' => $this->l('Lista de motivos (uno por línea: clave|Etiqueta)'),
                        'name'  => 'JOSRA_DESIST_MOTIVOS',
                        'tab'   => 'motivos',
                        'lang'  => true,
                        'rows'  => 8,
                        'desc'  => $this->l(
                            'Formato: clave|Etiqueta visible para el cliente (ej: defecto|El producto llegó con defecto). ' .
                            'No incluyas aquí "Otro motivo": tiene su propio campo justo abajo. Totalmente traducible: cada idioma tiene su propia lista.'
                        ),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Etiqueta de "Otro motivo"'),
                        'name'  => 'JOSRA_DESIST_MOTIVO_OTRO_LABEL',
                        'tab'   => 'motivos',
                        'lang'  => true,
                        'desc'  => $this->l('Siempre se muestra como última opción del desplegable. Solo puedes cambiar el texto, no eliminarla.'),
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Exigir detalle cuando el cliente elige "Otro motivo"'),
                        'name'   => 'JOSRA_DESIST_MOTIVO_OTRO_OBLIGATORIO',
                        'tab'    => 'motivos',
                        'values' => $sw,
                        'desc'   => $this->l('Si se activa, el campo de comentario será obligatorio al seleccionar el motivo "Otro".'),
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
                    [
                        'type'  => 'text',
                        'label' => $this->l('Email remitente (From)'),
                        'name'  => 'JOSRA_DESIST_EMAIL_REMITENTE',
                        'tab'   => 'notif',
                        'desc'  => $this->l('Dejar en blanco para usar el email de la tienda configurado en PrestaShop.'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Email de respuesta (Reply-To)'),
                        'name'  => 'JOSRA_DESIST_EMAIL_REPLYTO',
                        'tab'   => 'notif',
                        'desc'  => $this->l('Email al que llegarán las respuestas del cliente. Dejar en blanco para no añadir reply-to.'),
                    ],
                    // ---- FILTROS Y EXENCIONES ----
                    [
                        'type'    => 'checkbox',
                        'label'   => $this->l('Transportistas excluidos del desistimiento'),
                        'name'    => 'JOSRA_DESIST_CARRIERS_EXCLUIDOS',
                        'tab'     => 'filtros',
                        'values'  => [
                            'query' => $carriersQuery,
                            'id'    => 'id_carrier',
                            'name'  => 'name',
                        ],
                        'desc'    => $this->l('Los pedidos enviados con estos transportistas no podrán desistir (ej: recogida en tienda).'),
                    ],
                    [
                        'type'    => 'checkbox',
                        'label'   => $this->l('Estados de pedido excluidos del desistimiento'),
                        'name'    => 'JOSRA_DESIST_ESTADOS_EXCLUIDOS',
                        'tab'     => 'filtros',
                        'values'  => [
                            'query' => $orderStatesQuery,
                            'id'    => 'id_order_state',
                            'name'  => 'name',
                        ],
                        'desc'    => $this->l('Pedidos en estos estados no podrán solicitar el desistimiento.'),
                    ],
                    [
                        'type'    => 'checkbox',
                        'label'   => $this->l('Grupos de cliente B2B excluidos'),
                        'name'    => 'JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS',
                        'tab'     => 'filtros',
                        'values'  => [
                            'query' => $groupsQuery,
                            'id'    => 'id_group',
                            'name'  => 'name',
                        ],
                        'desc'    => $this->l('Los clientes profesionales (B2B) no tienen derecho de desistimiento como consumidores.'),
                    ],
                    [
                        'type'   => 'switch',
                        'label'  => $this->l('Excluir pedidos a nombre de una empresa'),
                        'name'   => 'JOSRA_DESIST_EXCLUIR_SI_EMPRESA',
                        'tab'    => 'filtros',
                        'values' => $sw,
                        'desc'   => $this->l('Si la dirección de facturación del pedido tiene el campo "Empresa" relleno, se considera B2B y se excluye.'),
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
                'pendiente'   => '<span class="label label-warning">Pendiente</span>',
                'aprobado'    => '<span class="label label-info">Aprobado</span>',
                'procesado'   => '<span class="label label-success">Procesado</span>',
                'completado'  => '<span class="label label-success">Completado</span>',
                'rechazado'   => '<span class="label label-danger">Rechazado</span>',
                'retenido'    => '<span class="label label-info">Retenido</span>',
                'expirado'    => '<span class="label label-default">Expirado</span>',
            ];
            $tokenAdmin = Tools::getAdminTokenLite('AdminModules');
            $currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;

            $html .= '<table class="table tableDnD"><thead><tr>
                <th>ID</th><th>Referencia</th><th>Cliente</th><th>Email</th>
                <th>Motivo</th><th>Estado</th><th>Retención</th><th>Fecha</th><th>' . $this->l('Acciones') . '</th>
            </tr></thead><tbody>';
            foreach ($rows as $r) {
                $badge = isset($badges[$r['estado']]) ? $badges[$r['estado']] : $r['estado'];
                $idDes = (int) $r['id_desistimiento'];
                $accionesForm = '
                    <form method="post" action="' . $currentIndex . '&token=' . $tokenAdmin . '" style="display:inline-block;">
                        <input type="hidden" name="id_desistimiento" value="' . $idDes . '">
                        <select name="josra_accion" onchange="if(this.value){this.form.submit();}" class="form-control input-sm" style="display:inline-block;width:auto;">
                            <option value="">' . $this->l('Acción...') . '</option>
                            <option value="aprobar">' . $this->l('Aprobar') . '</option>
                            <option value="rechazar">' . $this->l('Rechazar') . '</option>
                            <option value="marcar_recibido">' . $this->l('Marcar recibido') . '</option>
                            <option value="marcar_reembolsado">' . $this->l('Marcar reembolsado') . '</option>
                        </select>
                    </form>
                    <a href="#josra-nota-' . $idDes . '" data-toggle="collapse" class="btn btn-default btn-xs">' . $this->l('Nota') . '</a>
                    <div id="josra-nota-' . $idDes . '" class="collapse">
                        <form method="post" action="' . $currentIndex . '&token=' . $tokenAdmin . '" style="margin-top:5px;">
                            <input type="hidden" name="id_desistimiento" value="' . $idDes . '">
                            <input type="hidden" name="josra_accion" value="nota">
                            <input type="text" name="josra_nota" class="form-control input-sm" placeholder="' . $this->l('Nota interna...') . '" style="display:inline-block;width:200px;">
                            <button type="submit" class="btn btn-default btn-xs">' . $this->l('Guardar') . '</button>
                        </form>
                    </div>';
                $html .= '<tr>
                    <td>' . $idDes . '</td>
                    <td><strong>' . htmlspecialchars($r['reference']) . '</strong></td>
                    <td>' . htmlspecialchars($r['nombre']) . '</td>
                    <td>' . htmlspecialchars($r['email']) . '</td>
                    <td>' . htmlspecialchars($r['motivo']) . '</td>
                    <td>' . $badge . '</td>
                    <td>' . htmlspecialchars($r['opcion_retencion']) . '</td>
                    <td>' . htmlspecialchars($r['fecha_solicitud']) . '</td>
                    <td>' . $accionesForm . '</td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>';
        return $html;
    }

    /* =========================================================
     *  KPIs
     * ========================================================= */

    private function renderKpiPanel()
    {
        $tabla = _DB_PREFIX_ . 'josra_desistimiento';

        $pendientes = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . $tabla . '` WHERE `estado` = \'pendiente\''
        );

        $venceEn3Dias = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . $tabla . '`
             WHERE `estado` = \'pendiente\'
             AND `fecha_limite` IS NOT NULL
             AND `fecha_limite` BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)'
        );

        $resueltasMes = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . $tabla . '`
             WHERE `estado` IN (\'procesado\', \'completado\', \'rechazado\')
             AND MONTH(`fecha_procesado`) = MONTH(CURDATE())
             AND YEAR(`fecha_procesado`) = YEAR(CURDATE())'
        );

        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . $tabla . '`'
        );

        $kpis = [
            ['label' => $this->l('Pendientes'),              'valor' => $pendientes,   'color' => '#f39c12'],
            ['label' => $this->l('Vencen en 3 días'),         'valor' => $venceEn3Dias, 'color' => '#e74c3c'],
            ['label' => $this->l('Resueltas este mes'),       'valor' => $resueltasMes, 'color' => '#27ae60'],
            ['label' => $this->l('Total de solicitudes'),     'valor' => $total,        'color' => '#2980b9'],
        ];

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-dashboard"></i> '
            . $this->l('Indicadores') . '</div><div class="panel-body">
            <div class="row">';
        foreach ($kpis as $kpi) {
            $html .= '
                <div class="col-lg-3 col-md-6">
                    <div style="border-left:4px solid ' . $kpi['color'] . ';padding:10px 15px;margin-bottom:15px;background:#fafafa;">
                        <div style="font-size:26px;font-weight:700;color:' . $kpi['color'] . ';">' . $kpi['valor'] . '</div>
                        <div style="font-size:12px;color:#7f8c8d;text-transform:uppercase;">' . $kpi['label'] . '</div>
                    </div>
                </div>';
        }
        $html .= '</div></div></div>';
        return $html;
    }

    /* =========================================================
     *  CRON
     * ========================================================= */

    private function renderCronPanel()
    {
        $token = Configuration::get('JOSRA_DESIST_CRON_TOKEN');
        $url = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'module/' . $this->name . '/cron?token=' . $token;
        $currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $tokenAdmin = Tools::getAdminTokenLite('AdminModules');

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-time"></i> '
            . $this->l('Tarea programada (cron)') . '</div><div class="panel-body">
            <p>' . $this->l('Configura tu planificador de tareas (cron del servidor o servicio externo) para llamar a esta URL una vez al día. Expira las solicitudes vencidas y envía recordatorios antes del plazo límite.') . '</p>
            <div class="form-group">
                <label>' . $this->l('URL de la tarea cron') . '</label>
                <input type="text" class="form-control" readonly value="' . htmlspecialchars($url) . '" onclick="this.select();">
            </div>
            <form method="post" action="' . $currentIndex . '&token=' . $tokenAdmin . '">
                <button type="submit" name="submit_josra_regenerar_token" class="btn btn-default">
                    ' . $this->l('Regenerar token') . '
                </button>
                <span class="help-block" style="display:inline-block;margin-left:10px;">'
                    . $this->l('Si regeneras el token, deberás actualizar la URL en tu planificador.') . '</span>
            </form>
        </div></div>';
        return $html;
    }

    /* =========================================================
     *  FILTROS Y EXENCIONES — panel admin
     * ========================================================= */

    private function renderExclusionesPanel()
    {
        $currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $tokenAdmin = Tools::getAdminTokenLite('AdminModules');

        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento_exclusion` ORDER BY `date_add` DESC'
        );

        $tipoLabels = [
            'producto'   => $this->l('Producto'),
            'categoria'  => $this->l('Categoría'),
            'fabricante' => $this->l('Fabricante'),
            'proveedor'  => $this->l('Proveedor'),
        ];
        $modoLabels = [
            'excluir' => $this->l('Excluir del desistimiento'),
            'ampliar' => $this->l('Ampliar plazo'),
        ];

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-ban"></i> '
            . $this->l('Filtros y Exenciones por producto / categoría / fabricante / proveedor') . '</div>
            <div class="panel-body">
            <p>' . $this->l('Añade reglas para excluir del derecho de desistimiento (ej. productos personalizados, perecederos) o para ampliar el plazo para determinados artículos.') . '</p>

            <form method="post" action="' . $currentIndex . '&token=' . $tokenAdmin . '" class="form-inline" style="margin-bottom:15px;">
                <div class="form-group">
                    <label>' . $this->l('Tipo') . '</label>
                    <select name="josra_exclusion_tipo" class="form-control">
                        <option value="producto">' . $this->l('Producto') . '</option>
                        <option value="categoria">' . $this->l('Categoría') . '</option>
                        <option value="fabricante">' . $this->l('Fabricante') . '</option>
                        <option value="proveedor">' . $this->l('Proveedor') . '</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>' . $this->l('ID del objeto') . '</label>
                    <input type="number" name="josra_exclusion_id_objeto" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>' . $this->l('Modo') . '</label>
                    <select name="josra_exclusion_modo" class="form-control">
                        <option value="excluir">' . $this->l('Excluir del desistimiento') . '</option>
                        <option value="ampliar">' . $this->l('Ampliar plazo') . '</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>' . $this->l('Días ampliados') . '</label>
                    <input type="number" name="josra_exclusion_dias_ampliados" class="form-control" min="1" placeholder="' . $this->l('Solo si modo = ampliar') . '">
                </div>
                <button type="submit" name="submit_josra_exclusion_add" class="btn btn-default">
                    <i class="icon-plus"></i> ' . $this->l('Añadir regla') . '
                </button>
            </form>';

        if (empty($rows)) {
            $html .= '<p class="alert alert-info">' . $this->l('No hay reglas de exclusión configuradas.') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr>
                <th>ID</th><th>' . $this->l('Tipo') . '</th><th>' . $this->l('ID objeto') . '</th>
                <th>' . $this->l('Modo') . '</th><th>' . $this->l('Días ampliados') . '</th>
                <th>' . $this->l('Creado') . '</th><th></th>
            </tr></thead><tbody>';
            foreach ($rows as $r) {
                $idExclusion = (int) $r['id_exclusion'];
                $html .= '<tr>
                    <td>' . $idExclusion . '</td>
                    <td>' . (isset($tipoLabels[$r['tipo']]) ? $tipoLabels[$r['tipo']] : htmlspecialchars($r['tipo'])) . '</td>
                    <td>' . (int) $r['id_objeto'] . '</td>
                    <td>' . (isset($modoLabels[$r['modo']]) ? $modoLabels[$r['modo']] : htmlspecialchars($r['modo'])) . '</td>
                    <td>' . ($r['dias_ampliados'] !== null ? (int) $r['dias_ampliados'] : '—') . '</td>
                    <td>' . htmlspecialchars($r['date_add']) . '</td>
                    <td>
                        <form method="post" action="' . $currentIndex . '&token=' . $tokenAdmin . '" onsubmit="return confirm(\'' . $this->l('¿Eliminar esta regla?') . '\');" style="display:inline;">
                            <input type="hidden" name="josra_exclusion_delete" value="' . $idExclusion . '">
                            <button type="submit" class="btn btn-danger btn-xs"><i class="icon-trash"></i></button>
                        </form>
                    </td>
                </tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function postProcessExclusionAdd()
    {
        $tipo = Tools::getValue('josra_exclusion_tipo', 'producto');
        if (!in_array($tipo, ['producto', 'categoria', 'fabricante', 'proveedor'], true)) {
            $tipo = 'producto';
        }
        $idObjeto = (int) Tools::getValue('josra_exclusion_id_objeto');
        $modo = Tools::getValue('josra_exclusion_modo', 'excluir');
        if (!in_array($modo, ['excluir', 'ampliar'], true)) {
            $modo = 'excluir';
        }
        $diasAmpliados = Tools::getValue('josra_exclusion_dias_ampliados', '');
        $diasAmpliados = ($modo === 'ampliar' && $diasAmpliados !== '') ? (int) $diasAmpliados : null;

        if ($idObjeto <= 0) {
            return $this->displayError($this->l('Debes indicar un ID de objeto válido.'));
        }

        $ok = Db::getInstance()->insert('josra_desistimiento_exclusion', [
            'tipo'           => pSQL($tipo),
            'id_objeto'      => $idObjeto,
            'modo'           => pSQL($modo),
            'dias_ampliados' => $diasAmpliados,
            'date_add'       => date('Y-m-d H:i:s'),
        ]);

        return $ok
            ? $this->displayConfirmation($this->l('Regla de exclusión añadida correctamente.'))
            : $this->displayError($this->l('No se pudo guardar la regla de exclusión.'));
    }

    private function postProcessExclusionDelete()
    {
        $idExclusion = (int) Tools::getValue('josra_exclusion_delete');
        if ($idExclusion <= 0) {
            return $this->displayError($this->l('Regla no válida.'));
        }

        $ok = Db::getInstance()->delete('josra_desistimiento_exclusion', 'id_exclusion = ' . $idExclusion);

        return $ok
            ? $this->displayConfirmation($this->l('Regla de exclusión eliminada.'))
            : $this->displayError($this->l('No se pudo eliminar la regla.'));
    }

    /* =========================================================
     *  GESTIÓN DE SOLICITUDES — acciones de backoffice
     * ========================================================= */

    private function postProcessAccionSolicitud()
    {
        $idDesistimiento = (int) Tools::getValue('id_desistimiento');
        $accion = Tools::getValue('josra_accion');

        if ($idDesistimiento <= 0) {
            return $this->displayError($this->l('Solicitud no válida.'));
        }

        $solicitud = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento` WHERE `id_desistimiento` = ' . $idDesistimiento
        );
        if (!$solicitud) {
            return $this->displayError($this->l('Solicitud no encontrada.'));
        }

        $ahora = date('Y-m-d H:i:s');

        switch ($accion) {
            case 'aprobar':
                Db::getInstance()->update('josra_desistimiento', [
                    'estado'         => 'aprobado',
                    'fecha_aprobado' => $ahora,
                ], 'id_desistimiento = ' . $idDesistimiento);
                $this->addAuditoria($idDesistimiento, $this->l('Solicitud aprobada por el administrador.'));
                return $this->displayConfirmation($this->l('Solicitud aprobada correctamente.'));

            case 'rechazar':
                $motivoRechazo = pSQL(Tools::getValue('josra_motivo_rechazo', ''), true);
                Db::getInstance()->update('josra_desistimiento', [
                    'estado'         => 'rechazado',
                    'fecha_rechazado' => $ahora,
                    'fecha_procesado' => $ahora,
                    'motivo_rechazo' => $motivoRechazo,
                ], 'id_desistimiento = ' . $idDesistimiento);
                $this->addAuditoria($idDesistimiento, $this->l('Solicitud rechazada.') . ($motivoRechazo ? ' ' . $this->l('Motivo:') . ' ' . $motivoRechazo : ''));
                return $this->displayConfirmation($this->l('Solicitud rechazada.'));

            case 'marcar_recibido':
                Db::getInstance()->update('josra_desistimiento', [
                    'bienes_recibidos' => 1,
                    'fecha_recibido'   => $ahora,
                ], 'id_desistimiento = ' . $idDesistimiento);
                $this->addAuditoria($idDesistimiento, $this->l('Bienes recibidos marcados como recibidos por el comercio.'));
                return $this->displayConfirmation($this->l('Marcado como recibido.'));

            case 'marcar_reembolsado':
                Db::getInstance()->update('josra_desistimiento', [
                    'estado'            => 'completado',
                    'reembolsado'       => 1,
                    'fecha_reembolsado' => $ahora,
                    'fecha_procesado'   => $ahora,
                ], 'id_desistimiento = ' . $idDesistimiento);
                $this->addAuditoria($idDesistimiento, $this->l('Reembolso marcado manualmente. Solicitud completada.'));
                return $this->displayConfirmation($this->l('Marcado como reembolsado.'));

            case 'nota':
                $nota = pSQL(Tools::getValue('josra_nota', ''), true);
                if ($nota !== '') {
                    $linea = '[' . date('d/m/Y H:i:s') . '] ' . $nota;
                    Db::getInstance()->execute(
                        'UPDATE `' . _DB_PREFIX_ . 'josra_desistimiento`
                         SET `notas_internas` = TRIM(CONCAT(IFNULL(`notas_internas`, \'\'), \'\n\', \'' . pSQL($linea) . '\'))
                         WHERE `id_desistimiento` = ' . $idDesistimiento
                    );
                    $this->addAuditoria($idDesistimiento, $this->l('Nota interna añadida.'));
                }
                return $this->displayConfirmation($this->l('Nota guardada.'));

            default:
                return $this->displayError($this->l('Acción no reconocida.'));
        }
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

    /**
     * Completa automáticamente la solicitud cuando el pedido pasa a un estado
     * de "reembolsado" (detectado por nombre, igual que getEstadosExcluidos()
     * en el controlador front). Evita tener que cerrar manualmente la solicitud
     * cuando el reembolso ya se ha hecho desde el pedido.
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $newOrderStatus = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!$idOrder || !$newOrderStatus || !Validate::isLoadedObject($newOrderStatus)) {
            return;
        }

        $nombreEstado = Tools::strtolower($newOrderStatus->name[(int) Configuration::get('PS_LANG_DEFAULT')] ?? '');
        $esReembolso = (strpos($nombreEstado, 'reembols') !== false) || (strpos($nombreEstado, 'refund') !== false);
        if (!$esReembolso) {
            return;
        }

        $solicitud = Db::getInstance()->getRow(
            'SELECT `id_desistimiento` FROM `' . _DB_PREFIX_ . 'josra_desistimiento`
             WHERE `id_order` = ' . $idOrder . '
             AND `estado` NOT IN (\'rechazado\', \'completado\', \'expirado\')
             ORDER BY `fecha_solicitud` DESC'
        );

        if (!$solicitud) {
            return;
        }

        Db::getInstance()->update('josra_desistimiento', [
            'estado'            => 'completado',
            'reembolsado'       => 1,
            'fecha_reembolsado' => date('Y-m-d H:i:s'),
            'fecha_procesado'   => date('Y-m-d H:i:s'),
        ], 'id_desistimiento = ' . (int) $solicitud['id_desistimiento']);

        $this->addAuditoria((int) $solicitud['id_desistimiento'], $this->l('Pedido marcado como reembolsado en PrestaShop. Solicitud completada automáticamente.'));
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

        $motivoLabels = $this->getMotivos((int) $this->context->language->id);
        $motivoLabel = isset($motivoLabels[$solicitud['motivo']])
            ? htmlspecialchars($motivoLabels[$solicitud['motivo']])
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

    /* =========================================================
     *  AUDITORÍA
     * ========================================================= */

    public function addAuditoria($idDesistimiento, $texto)
    {
        $linea = '[' . date('d/m/Y H:i:s') . '] ' . $texto;
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josra_desistimiento`
             SET `auditoria` = TRIM(CONCAT(IFNULL(`auditoria`, \'\'), \'\n\', \'' . pSQL($linea) . '\'))
             WHERE `id_desistimiento` = ' . (int) $idDesistimiento
        );
    }

    /* =========================================================
     *  ACUSE DE RECIBO EN PDF
     * ========================================================= */

    /**
     * Genera el PDF de acuse de recibo de una solicitud de desistimiento.
     * Requiere TCPDF (incluido de serie en PrestaShop). Si no está disponible,
     * devuelve null y el email se envía sin adjunto (no es un error fatal).
     */
    public function generarPdfAcuseRecibo($idDesistimiento)
    {
        if (!class_exists('TCPDF')) {
            return null;
        }

        $solicitud = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento` WHERE `id_desistimiento` = ' . (int) $idDesistimiento
        );
        if (!$solicitud) {
            return null;
        }

        $motivos = $this->getMotivos((int) $this->context->language->id);
        $motivoLabel = isset($motivos[$solicitud['motivo']]) ? $motivos[$solicitud['motivo']] : $solicitud['motivo'];

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('PrestaShop');
        $pdf->SetTitle($this->l('Acuse de recibo de desistimiento') . ' #JOSRA-' . (int) $idDesistimiento);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $shopName = Configuration::get('PS_SHOP_NAME');

        $html = '<h2>' . $this->l('Acuse de recibo de solicitud de desistimiento') . '</h2>'
            . '<p>' . $shopName . '</p>'
            . '<hr>'
            . '<p><strong>' . $this->l('Número de solicitud') . ':</strong> #JOSRA-' . (int) $idDesistimiento . '</p>'
            . '<p><strong>' . $this->l('Referencia del pedido') . ':</strong> ' . htmlspecialchars($solicitud['reference']) . '</p>'
            . '<p><strong>' . $this->l('Cliente') . ':</strong> ' . htmlspecialchars($solicitud['nombre']) . ' (' . htmlspecialchars($solicitud['email']) . ')</p>'
            . '<p><strong>' . $this->l('Motivo') . ':</strong> ' . htmlspecialchars($motivoLabel) . '</p>'
            . '<p><strong>' . $this->l('Fecha de la solicitud') . ':</strong> ' . htmlspecialchars($solicitud['fecha_solicitud']) . '</p>'
            . '<p>' . $this->l('Hemos recibido tu solicitud de desistimiento de acuerdo con la Directiva (UE) 2023/2673. Este documento sirve como acuse de recibo inmediato.') . '</p>';

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('acuse_desistimiento_' . (int) $idDesistimiento . '.pdf', 'S');
    }

    /* =========================================================
     *  FILTROS Y EXENCIONES (productos, categorías, fabricantes,
     *  proveedores, transportistas, estados de pedido, B2B)
     * ========================================================= */

    /**
     * Calcula el plazo de desistimiento (en días) aplicable a un pedido,
     * según las exenciones configuradas para sus productos. Si CUALQUIER
     * producto del pedido tiene una regla de "ampliar", se usa el plazo
     * más alto. Devuelve null si TODOS los productos del pedido están
     * excluidos del derecho de desistimiento (art. 16 Directiva 2011/83/UE).
     */
    public function getDiasParaPedido(Order $order)
    {
        $diasBase = (int) Configuration::get('JOSRA_DESIST_DIAS') ?: 14;
        $productos = $order->getProducts();

        if (empty($productos)) {
            return $diasBase;
        }

        $reglas = $this->getReglasExclusion();
        if (empty($reglas)) {
            return $diasBase;
        }

        $diasMax = null;
        $totalProductos = count($productos);
        $excluidos = 0;

        foreach ($productos as $producto) {
            $regla = $this->buscarReglaProducto($producto, $reglas);
            if ($regla === null) {
                $diasMax = max($diasMax === null ? $diasBase : $diasMax, $diasBase);
                continue;
            }
            if ($regla['modo'] === 'excluir') {
                $excluidos++;
                continue;
            }
            $dias = (int) $regla['dias_ampliados'] ?: $diasBase;
            $diasMax = max($diasMax === null ? $dias : $diasMax, $dias, $diasBase);
        }

        if ($excluidos >= $totalProductos) {
            return null; // todo el pedido está excluido del derecho de desistimiento
        }

        return $diasMax !== null ? $diasMax : $diasBase;
    }

    private function getReglasExclusion()
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_desistimiento_exclusion`'
        );
    }

    private function buscarReglaProducto(array $producto, array $reglas)
    {
        $idProduct = (int) $producto['product_id'];
        $idCategoria = isset($producto['id_category_default']) ? (int) $producto['id_category_default'] : 0;
        $idFabricante = isset($producto['id_manufacturer']) ? (int) $producto['id_manufacturer'] : 0;
        $idProveedor = isset($producto['id_supplier']) ? (int) $producto['id_supplier'] : 0;

        // Prioridad: producto > categoría > fabricante > proveedor
        foreach (['producto' => $idProduct, 'categoria' => $idCategoria, 'fabricante' => $idFabricante, 'proveedor' => $idProveedor] as $tipo => $idObjeto) {
            if (!$idObjeto) {
                continue;
            }
            foreach ($reglas as $regla) {
                if ($regla['tipo'] === $tipo && (int) $regla['id_objeto'] === $idObjeto) {
                    return $regla;
                }
            }
        }
        return null;
    }

    /**
     * Determina si un pedido es elegible para desistimiento según los filtros
     * de transportista, estado de pedido y exclusión B2B configurados.
     * Devuelve un array ['elegible' => bool, 'motivo' => string|null].
     */
    public function esPedidoElegible(Order $order)
    {
        // Transportista excluido
        $carriersExcluidos = array_filter(array_map('intval', explode(',', (string) Configuration::get('JOSRA_DESIST_CARRIERS_EXCLUIDOS'))));
        if ($carriersExcluidos && in_array((int) $order->id_carrier, $carriersExcluidos, true)) {
            return ['elegible' => false, 'motivo' => $this->l('Transportista no elegible para desistimiento.')];
        }

        // Estado de pedido excluido (configurado manualmente)
        $estadosExcluidos = array_filter(array_map('intval', explode(',', (string) Configuration::get('JOSRA_DESIST_ESTADOS_EXCLUIDOS'))));
        if ($estadosExcluidos && in_array((int) $order->current_state, $estadosExcluidos, true)) {
            return ['elegible' => false, 'motivo' => $this->l('El estado actual del pedido no permite el desistimiento.')];
        }

        // Exclusión B2B por grupo de cliente
        $gruposExcluidos = array_filter(array_map('intval', explode(',', (string) Configuration::get('JOSRA_DESIST_GRUPOS_B2B_EXCLUIDOS'))));
        if ($gruposExcluidos) {
            $customer = new Customer((int) $order->id_customer);
            if (Validate::isLoadedObject($customer) && in_array((int) $customer->id_default_group, $gruposExcluidos, true)) {
                return ['elegible' => false, 'motivo' => $this->l('Los clientes profesionales (B2B) no tienen derecho de desistimiento.')];
            }
        }

        // Exclusión B2B por empresa indicada en la dirección de facturación del pedido
        if (Configuration::get('JOSRA_DESIST_EXCLUIR_SI_EMPRESA') && $order->id_address_invoice) {
            $address = new Address((int) $order->id_address_invoice);
            if (Validate::isLoadedObject($address) && !empty($address->company)) {
                return ['elegible' => false, 'motivo' => $this->l('Pedido a nombre de una empresa: excluido del derecho de desistimiento del consumidor.')];
            }
        }

        return ['elegible' => true, 'motivo' => null];
    }
}
