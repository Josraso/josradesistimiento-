{**
 * Template: formulario.tpl
 * PASO 1 — Formulario de desistimiento
 * Cumple art. 11 bis Directiva (UE) 2023/2673:
 *   - Datos mínimos (nombre, email, referencia, motivo)
 *   - Sin registro obligatorio para clientes invitados
 *   - Flujo en 2 pasos (este formulario + confirmación)
 **}

{extends file='page.tpl'}

{block name='page_title'}
  {l s='Solicitud de desistimiento' mod='josradesistimiento'}
{/block}

{block name='page_content'}
<div class="josra-page-formulario container">

  <div class="josra-aviso-legal alert alert-info">
    <p>
      <strong>{l s='Tu derecho de desistimiento' mod='josradesistimiento'}</strong>
    </p>
    <p>
      {l s='De acuerdo con la Directiva (UE) 2023/2673 y la normativa de protección al consumidor, tienes derecho a desistir de tu compra online en un plazo de %s días naturales desde la recepción del producto, sin necesidad de justificación.' sprintf=[$josra_dias] mod='josradesistimiento'}
    </p>
    <p>
      {l s='Rellena el formulario y recibirás inmediatamente un email de confirmación como acuse de recibo.' mod='josradesistimiento'}
    </p>
    {if $josra_gastos_devolucion == 'comercio'}
      <p>{l s='Los gastos de devolución corren a cargo del comercio.' mod='josradesistimiento'}</p>
    {else}
      <p>{l s='Los gastos de devolución corren a cargo del cliente, salvo que se indique lo contrario.' mod='josradesistimiento'}</p>
    {/if}
    {if $josra_direccion_devolucion}
      <p>
        <strong>{l s='Dirección de devolución:' mod='josradesistimiento'}</strong>
        {$josra_direccion_devolucion|escape:'html':'UTF-8'|nl2br}
      </p>
    {/if}
    {if $josra_politica_texto}
      <p>{$josra_politica_texto|escape:'html':'UTF-8'|nl2br}</p>
    {elseif $josra_politica_url}
      <p>
        <a href="{$josra_politica_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
          {l s='Consulta la política de desistimiento completa' mod='josradesistimiento'}
        </a>
      </p>
    {/if}
  </div>

  {if isset($josra_errores) && $josra_errores}
    <div class="alert alert-danger">
      <ul>
        {foreach $josra_errores as $error}
          <li>{$error|escape:'html':'UTF-8'}</li>
        {/foreach}
      </ul>
    </div>
  {/if}

  <form method="POST"
        action="{$josra_action_url|escape:'html':'UTF-8'}"
        id="josra-form-desistimiento"
        novalidate>

    <input type="hidden" name="action" value="enviar">
    <input type="hidden" name="token" value="{$josra_token|escape:'html':'UTF-8'}">

    <div class="form-group">
      <label for="josra_nombre" class="required">
        {l s='Nombre completo' mod='josradesistimiento'}
      </label>
      <input type="text"
             id="josra_nombre"
             name="josra_nombre"
             class="form-control"
             required
             maxlength="150"
             value="{$josra_nombre|escape:'html':'UTF-8'}"
             autocomplete="name"
             placeholder="{l s='Tu nombre y apellidos' mod='josradesistimiento'}">
    </div>

    <div class="form-group">
      <label for="josra_email" class="required">
        {l s='Email' mod='josradesistimiento'}
      </label>
      <input type="email"
             id="josra_email"
             name="josra_email"
             class="form-control"
             required
             maxlength="255"
             value="{$josra_email|escape:'html':'UTF-8'}"
             autocomplete="email"
             placeholder="{l s='El email con el que realizaste el pedido' mod='josradesistimiento'}">
    </div>

    <div class="form-group">
      <label for="josra_referencia" class="required">
        {l s='Referencia del pedido' mod='josradesistimiento'}
      </label>

      {if $josra_pedidos && count($josra_pedidos) > 0}
        <select id="josra_referencia" name="josra_referencia" class="form-control custom-select" required>
          <option value="">{l s='-- Selecciona tu pedido --' mod='josradesistimiento'}</option>
          {foreach $josra_pedidos as $pedido}
            <option value="{$pedido.reference|escape:'html':'UTF-8'}">
              #{$pedido.reference|escape:'html':'UTF-8'}
              — {$pedido.date_add|date_format:'%d/%m/%Y'}
              — {$pedido.precio_formateado|escape:'html':'UTF-8'}
            </option>
          {/foreach}
        </select>
        <small class="form-text text-muted">
          {l s='Se muestran tus pedidos activos de los últimos 30 días. El sistema verificará automáticamente si están dentro del plazo de desistimiento (14 días desde la entrega).' mod='josradesistimiento'}
        </small>
      {else}
        <input type="text"
               id="josra_referencia"
               name="josra_referencia"
               class="form-control"
               required
               maxlength="64"
               placeholder="{l s='Ej: XJKML4532' mod='josradesistimiento'}"
               value="{if isset($smarty.get.josra_referencia)}{$smarty.get.josra_referencia|escape:'html':'UTF-8'}{/if}">
        <small class="form-text text-muted">
          {l s='Puedes encontrar la referencia en el email de confirmación de tu pedido.' mod='josradesistimiento'}
        </small>
      {/if}
    </div>

    <div class="form-group">
      <label for="josra_motivo" class="required">
        {l s='Motivo del desistimiento' mod='josradesistimiento'}
      </label>
      <select id="josra_motivo"
              name="josra_motivo"
              class="form-control custom-select"
              data-otro-obligatorio="{if $josra_motivo_otro_obligatorio}1{else}0{/if}"
              required>
        <option value="">{l s='-- Selecciona un motivo --' mod='josradesistimiento'}</option>
        {foreach $josra_motivos as $key => $label}
          <option value="{$key|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</option>
        {/foreach}
      </select>
      <small class="form-text text-muted">
        {l s='Indicar el motivo es opcional según la Directiva, pero nos ayuda a mejorar.' mod='josradesistimiento'}
      </small>
    </div>

    <div class="form-group">
      <label for="josra_comentario" id="josra_comentario_label">
        {l s='Comentario adicional (opcional)' mod='josradesistimiento'}
      </label>
      <textarea id="josra_comentario"
                name="josra_comentario"
                class="form-control"
                rows="3"
                maxlength="1000"
                placeholder="{l s='Si deseas añadir más detalles...' mod='josradesistimiento'}"></textarea>
      {if $josra_motivo_otro_obligatorio}
        <small class="form-text text-muted">
          {l s='Si seleccionas "Otro motivo", deberás detallarlo aquí.' mod='josradesistimiento'}
        </small>
      {/if}
    </div>

    <div class="josra-gdpr-notice">
      <small>
        {l s='Los datos que nos facilitas se usarán exclusivamente para gestionar tu solicitud de desistimiento y enviarte el acuse de recibo correspondiente, conforme a nuestra política de privacidad.' mod='josradesistimiento'}
      </small>
    </div>

    <div class="josra-form-actions">
      <button type="submit"
              id="josra-btn-enviar"
              class="btn btn-primary josra-btn-submit"
              data-loading-text="{l s='Enviando...' mod='josradesistimiento'}">
        {l s='Enviar solicitud de desistimiento' mod='josradesistimiento'}
      </button>
      <a href="{$urls.pages.index}"
         class="btn btn-default josra-btn-cancelar">
        {l s='Cancelar y volver a la tienda' mod='josradesistimiento'}
      </a>
    </div>

  </form>

</div>
{/block}
