{extends file='page.tpl'}
{block name='page_title'}{l s='Error' mod='josradesistimiento'}{/block}
{block name='page_content'}
<div class="josra-page-error container">
  <div class="alert alert-danger">
    <p>{$josra_error_fatal|escape:'html':'UTF-8'}</p>
  </div>
  <a href="{$urls.pages.index}" class="btn btn-default">{l s='Volver a la tienda' mod='josradesistimiento'}</a>
</div>
{/block}
