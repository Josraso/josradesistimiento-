# josradesistimiento — Módulo PrestaShop

## Botón de Desistimiento Obligatorio — Directiva (UE) 2023/2673

Módulo totalmente funcional para PrestaShop **1.7, 8.x y 9.x** que implementa el botón de desistimiento obligatorio según el artículo 11 bis de la Directiva (UE) 2023/2673, con estrategia de retención integrada.

---

## ✅ Qué cumple este módulo

| Requisito legal | Implementado |
|---|---|
| Botón visible e inequívoco ("Desistir del contrato aquí") | ✅ |
| Accesible en toda la interfaz (footer, producto, cuenta, pedido) | ✅ |
| Sin barrera de registro para clientes invitados | ✅ |
| Flujo en dos pasos (formulario + confirmación) | ✅ |
| Solo datos mínimos (nombre, email, referencia, motivo) | ✅ |
| Confirmación automática por email con fecha y hora | ✅ |
| Verificación del plazo de 14 días | ✅ |
| Registro de solicitudes en base de datos | ✅ |
| Prevención de duplicados | ✅ |

---

## 🚀 Instalación

1. Sube la carpeta `josradesistimiento` a `/modules/` de tu PrestaShop.
2. Ve a **Backoffice → Módulos → Gestión de módulos**.
3. Busca "Botón de Desistimiento" e instala.
4. Configura desde **Módulos → josradesistimiento → Configurar**.

---

## ⚙️ Configuración

### Pestaña Visibilidad
- Activa/desactiva el botón en footer, ficha de producto, área de cliente.
- Ajusta los días de plazo (por defecto 14, no reducir sin asesoría legal).

### Pestaña Retención ⭐
La estrategia de retención convierte devoluciones en ingresos:

- **Saldo en tienda con bonificación**: ofrece X% extra sobre el valor del reembolso. El dinero se queda en la tienda.
- **Cambio de producto**: para motivos de talla/color, gestiona el cambio sin coste.
- **El cliente siempre puede ignorarlo** y continuar con el desistimiento → cumplimiento legal garantizado.

### Pestaña Textos y Diseño
- Personaliza el texto del botón (debe ser inequívoco).
- Elige el color corporativo.

### Pestaña Notificaciones
- Email de confirmación al cliente (obligatorio por Directiva).
- Email de copia al comercio.

---

## 📍 Dónde aparece el botón

| Ubicación | Hook PrestaShop | Activable |
|---|---|---|
| Footer de la web | `displayFooter` | ✅ Configurable |
| Ficha de producto | `displayProductAdditionalInfo` | ✅ Configurable |
| Área de cliente | `displayCustomerAccount` | ✅ Configurable |
| Detalle del pedido | `displayOrderDetail` | ✅ Automático |

---

## 📁 Estructura del módulo

```
josradesistimiento/
├── josradesistimiento.php          # Módulo principal
├── controllers/
│   └── front/
│       └── desistir.php            # Controlador (formulario, validación, email)
├── views/
│   ├── templates/front/
│   │   ├── boton.tpl               # Widget botón (multi-contexto)
│   │   ├── formulario.tpl          # Paso 1: formulario
│   │   ├── confirmacion.tpl        # Paso 2: acuse de recibo + retención
│   │   ├── retencion_ok.tpl        # Resultado elección retención
│   │   └── error.tpl               # Errores fatales
│   ├── css/
│   │   └── josradesistimiento.css
│   ├── js/
│   │   └── josradesistimiento.js
│   └── mail/
│       └── es/
│           ├── desistimiento_confirmacion.html
│           ├── desistimiento_confirmacion.txt
│           ├── desistimiento_aviso_comercio.html
│           └── desistimiento_aviso_comercio.txt
├── sql/
│   └── install.sql
├── translations/
│   └── es.php
└── upgrade/
    └── upgrade-1.0.1.php
```

---

## 🔒 Seguridad

- Token CSRF en el formulario.
- Validación server-side de todos los campos.
- Verificación de que el email del formulario coincide con el del pedido.
- Control de duplicados: no se puede presentar dos veces la misma referencia.
- `pSQL()` en todas las consultas SQL.
- IP registrada en cada solicitud.

---

## 📊 Gestión de solicitudes (backoffice)

Las últimas 50 solicitudes se muestran en la configuración del módulo con:
- Estado: Pendiente / Procesado / Rechazado / Retenido
- Opción de retención elegida por el cliente
- Fecha y hora de solicitud

---

## ⚖️ Aviso legal

Este módulo implementa los requisitos técnicos de la Directiva (UE) 2023/2673. Se recomienda complementarlo con:
- Política de devoluciones actualizada.
- Referencia al botón en la información precontractual y condiciones de venta.
- Asesoría legal para adaptación específica a tu país/sector.

---

## 📞 Soporte

Módulo desarrollado por **josra**.
