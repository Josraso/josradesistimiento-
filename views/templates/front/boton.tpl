{**
 * Template: boton.tpl
 * Renderiza el botón de desistimiento en el contexto indicado
 * Cumple Directiva (UE) 2023/2673 — visible, inequívoco, sin barreras
 **}

<div class="josra-desistimiento-widget josra-contexto-{$josra_contexto|escape:'html':'UTF-8'}">

  {if $josra_contexto === 'footer'}
    <div class="josra-footer-block">
      <span class="josra-texto-legal">
        {l s='Tienes derecho a desistir de tu compra en un plazo de %s días.' sprintf=[$josra_dias] mod='josradesistimiento'}
      </span>
      <a href="{$josra_url_desistir|escape:'html':'UTF-8'}"
         class="josra-btn-desistir"
         style="background-color:{$josra_color_boton|escape:'html':'UTF-8'}"
         aria-label="{l s='Iniciar proceso de desistimiento' mod='josradesistimiento'}">
        <i class="material-icons josra-icon" aria-hidden="true">undo</i>
        {$josra_texto_boton|escape:'html':'UTF-8'}
      </a>
    </div>

  {elseif $josra_contexto === 'pedido' && $josra_referencia}
    <div class="josra-pedido-block">
      <a href="{$josra_url_desistir|escape:'html':'UTF-8'}?josra_referencia={$josra_referencia|urlencode}"
         class="josra-btn-desistir josra-btn-sm"
         style="background-color:{$josra_color_boton|escape:'html':'UTF-8'}">
        {$josra_texto_boton|escape:'html':'UTF-8'}
      </a>
      <small class="josra-plazo-info">
        {l s='Derecho de desistimiento: %s días desde la entrega.' sprintf=[$josra_dias] mod='josradesistimiento'}
      </small>
    </div>

  {elseif $josra_contexto === 'producto'}
    <div class="josra-producto-block">
      <a href="{$josra_url_desistir|escape:'html':'UTF-8'}"
         class="josra-btn-desistir josra-btn-outline"
         style="border-color:{$josra_color_boton|escape:'html':'UTF-8'}; color:{$josra_color_boton|escape:'html':'UTF-8'}">
        {$josra_texto_boton|escape:'html':'UTF-8'}
      </a>
      <small class="josra-plazo-info">
        {l s='%s días para desistir desde la recepción.' sprintf=[$josra_dias] mod='josradesistimiento'}
      </small>
    </div>

  {else}
    <div class="josra-cuenta-block">
      <a href="{$josra_url_desistir|escape:'html':'UTF-8'}"
         class="josra-btn-desistir"
         style="background-color:{$josra_color_boton|escape:'html':'UTF-8'}">
        {$josra_texto_boton|escape:'html':'UTF-8'}
      </a>
    </div>
  {/if}

</div>
