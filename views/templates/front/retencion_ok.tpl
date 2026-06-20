{**
 * Template: retencion_ok.tpl
 * Resultado tras elegir opción de retención
 **}

{extends file='page.tpl'}

{block name='page_title'}
  {l s='Elección registrada' mod='josradesistimiento'}
{/block}

{block name='page_content'}
<div class="josra-page-retencion container">

  {if $josra_opcion === 'saldo'}
    <div class="alert alert-success">
      <h4>💳 {l s='¡Perfecto, %s!' sprintf=[$josra_nombre] mod='josradesistimiento'}</h4>
      <p>
        {l s='Hemos registrado tu preferencia de recibir saldo en tienda con un %s%% de bonificación en lugar del reembolso directo.' sprintf=[$josra_bono_pct] mod='josradesistimiento'}
      </p>
      <p>
        {l s='Nos pondremos en contacto contigo en breve para gestionar el abono del saldo.' mod='josradesistimiento'}
      </p>
    </div>

  {elseif $josra_opcion === 'cambio'}
    <div class="alert alert-success">
      <h4>🔄 {l s='Cambio registrado, %s' sprintf=[$josra_nombre] mod='josradesistimiento'}</h4>
      <p>
        {l s='Has optado por cambiar el producto del pedido %s.' sprintf=[$josra_referencia] mod='josradesistimiento'}
      </p>
      <p>
        {l s='Nuestro equipo de atención al cliente se pondrá en contacto contigo para coordinar el cambio.' mod='josradesistimiento'}
      </p>
    </div>

  {else}
    <div class="alert alert-info">
      <h4>{l s='Desistimiento confirmado' mod='josradesistimiento'}</h4>
      <p>
        {l s='Tu solicitud de desistimiento para el pedido %s está en trámite. Nos pondremos en contacto contigo para indicarte los pasos a seguir.' sprintf=[$josra_referencia] mod='josradesistimiento'}
      </p>
    </div>
  {/if}

  <div class="josra-volver">
    <a href="{$urls.pages.index}" class="btn btn-default">
      {l s='Volver a la tienda' mod='josradesistimiento'}
    </a>
  </div>

</div>
{/block}
