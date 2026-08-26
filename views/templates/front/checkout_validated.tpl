<section class="card">
  <div class="card-block">
    <h1>{l s='Поръчката за финансиране е създадена' d='Modules.Unipayment.Shop'}</h1>
    {if isset($unipayment_smartucf_outcome_unknown) && $unipayment_smartucf_outcome_unknown}
      <p class="alert alert-warning">
        {if isset($unipayment_smartucf_message) && $unipayment_smartucf_message}
          {$unipayment_smartucf_message|escape:'htmlall':'UTF-8'}
        {else}
          {l s='Поръчката е създадена, но потвърждението от банковата система не беше получено. Не изпращайте заявката повторно.' d='Modules.Unipayment.Shop'}
        {/if}
      </p>
    {elseif isset($unipayment_smartucf_processing) && $unipayment_smartucf_processing}
      <p>
        {if isset($unipayment_smartucf_message) && $unipayment_smartucf_message}
          {$unipayment_smartucf_message|escape:'htmlall':'UTF-8'}
        {else}
          {l s='Заявката към банката се обработва. Моля, изчакайте.' d='Modules.Unipayment.Shop'}
        {/if}
      </p>
    {elseif isset($unipayment_phase10_recovered) && $unipayment_phase10_recovered}
      <p>{l s='Поръчката вече е обработена. Не изпращайте заявката повторно.' d='Modules.Unipayment.Shop'}</p>
    {else}
      <p>{l s='Поръчката е създадена и регистрирана за финансиране с УниКредит. Все още не е стартирана банкова заявка.' d='Modules.Unipayment.Shop'}</p>
    {/if}
    <dl>
      <dt>{l s='Референция на поръчката' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.order_reference|escape:'htmlall':'UTF-8'}</dd>
      <dt>{l s='ID на поръчката' d='Modules.Unipayment.Shop'}</dt><dd>{$unipayment_order_result.id_order|intval}</dd>
    </dl>
  </div>
</section>
