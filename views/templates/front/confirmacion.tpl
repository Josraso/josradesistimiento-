{**
 * Template: confirmacion.tpl v1.1.0
 * PASO 2 — Confirmación + retención. Sin sprintf en {l}, texto fijo desde PHP.
 **}

{extends file='page.tpl'}

{block name='page_title'}
  {l s='Solicitud recibida' mod='josradesistimiento'}
{/block}

{block name='page_content'}
<div class="josra-page-confirmacion container">

  {* ——— ACUSE DE RECIBO OBLIGATORIO ——— *}
  <div class="josra-acuse alert alert-success">
    <h4>
      <i class="material-icons">check_circle</i>
      {l s='Tu solicitud de desistimiento ha sido recibida' mod='josradesistimiento'}
    </h4>
    <p><strong>{l s='Hola' mod='josradesistimiento'} {$josra_nombre|escape:'html':'UTF-8'},</strong></p>
    <p>{$josra_msg_recibido|escape:'html':'UTF-8'}</p>
    <p>{$josra_msg_email|escape:'html':'UTF-8'}</p>
    <p class="josra-numero-solicitud">
      <strong>{l s='Número de solicitud:' mod='josradesistimiento'}</strong>
      #JOSRA-{$josra_id_desistimiento|intval}
    </p>
  </div>

  {* ——— ESTRATEGIA DE RETENCIÓN (solo si está activa) ——— *}
  {if $josra_retencion}
  <div class="josra-retencion-bloque card">
    <div class="card-header josra-retencion-header">
      <h5>{l s='¿Podemos hacer algo antes de tramitar la devolución?' mod='josradesistimiento'}</h5>
    </div>
    <div class="card-body">
      <p>{l s='Antes de proceder con el reembolso, nos gustaría ofrecerte algunas alternativas. Si prefieres seguir con el desistimiento, selecciona la última opción.' mod='josradesistimiento'}</p>

      <form method="POST" action="{$josra_action_url|escape:'html':'UTF-8'}" id="josra-form-retencion">
        <input type="hidden" name="action" value="retencion">
        <input type="hidden" name="josra_id_desistimiento" value="{$josra_id_desistimiento|intval}">
        <input type="hidden" name="josra_token_solicitud" value="{$josra_token_solicitud|escape:'html':'UTF-8'}">

        <div class="josra-opciones-retencion">

          {if $josra_bono_pct > 0}
          <div class="josra-opcion josra-opcion-saldo">
            <label class="josra-opcion-label">
              <input type="radio" name="josra_opcion_retencion" value="saldo">
              <div class="josra-opcion-contenido">
                <span class="josra-opcion-titulo">💳 {$josra_texto_saldo|escape:'html':'UTF-8'}</span>
                <span class="josra-opcion-desc">{$josra_desc_saldo|escape:'html':'UTF-8'}</span>
              </div>
            </label>
          </div>
          {/if}

          <div class="josra-opcion josra-opcion-cambio">
            <label class="josra-opcion-label">
              <input type="radio" name="josra_opcion_retencion" value="cambio">
              <div class="josra-opcion-contenido">
                <span class="josra-opcion-titulo">🔄 {l s='Prefiero cambiar el producto' mod='josradesistimiento'}</span>
                <span class="josra-opcion-desc">{l s='Si el motivo es la talla, el color o simplemente te has decantado por otro modelo, gestionamos el cambio sin coste adicional.' mod='josradesistimiento'}</span>
              </div>
            </label>
          </div>

          <div class="josra-opcion josra-opcion-continuar">
            <label class="josra-opcion-label">
              <input type="radio" name="josra_opcion_retencion" value="continuar" checked>
              <div class="josra-opcion-contenido">
                <span class="josra-opcion-titulo">✓ {l s='Continuar con el desistimiento y el reembolso' mod='josradesistimiento'}</span>
                <span class="josra-opcion-desc">{l s='Tramitaremos la devolución y el reembolso en los plazos legales.' mod='josradesistimiento'}</span>
              </div>
            </label>
          </div>

        </div>

        <div class="josra-retencion-actions">
          <button type="submit" class="btn btn-primary">
            {l s='Confirmar mi elección' mod='josradesistimiento'}
          </button>
        </div>
      </form>
    </div>
  </div>
  {/if}

  <div class="josra-info-adicional">
    <h6>{l s='¿Qué ocurre ahora?' mod='josradesistimiento'}</h6>
    <ul>
      <li>{l s='Recibirás el email de confirmación en los próximos minutos.' mod='josradesistimiento'}</li>
      <li>{l s='Nuestro equipo procesará tu solicitud y te contactará para indicarte los pasos a seguir.' mod='josradesistimiento'}</li>
    </ul>
    <a href="{$urls.pages.index}" class="btn btn-default">{l s='Volver a la tienda' mod='josradesistimiento'}</a>
  </div>

</div>
{/block}
