<section class="alert alert-danger" role="alert">
  <h1>{l s='Изборът на финансиране не е валидиран' d='Modules.Unipayment.Shop'}</h1>
  <p>{$unipayment_checkout_error|escape:'htmlall':'UTF-8'}</p>
  <p><a href="{$unipayment_checkout_return_url|escape:'htmlall':'UTF-8'}">{l s='Обратно към checkout' d='Modules.Unipayment.Shop'}</a></p>
</section>
