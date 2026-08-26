<section class="card">
  <div class="card-block">
    <h1>{l s='Заявката се обработва' d='Modules.Unipayment.Shop'}</h1>
    <p>
      {if isset($unipayment_checkout_processing_message) && $unipayment_checkout_processing_message}
        {$unipayment_checkout_processing_message|escape:'htmlall':'UTF-8'}
      {else}
        {l s='Your financing request is currently being processed.' d='Modules.Unipayment.Shop'}
      {/if}
    </p>
  </div>
</section>
