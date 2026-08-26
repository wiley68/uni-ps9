<section class="unipayment-thankyou-credit-details">
    <h3 class="unipayment-thankyou-credit-details__title">{l s='УниКредит лизинг' d='Modules.Unipayment.Shop'}</h3>
    {if isset($unipayment_leasing_rows) && $unipayment_leasing_rows|@count > 0}
        <table class="unipayment-thankyou-credit-details__table">
            <tbody>
                {foreach from=$unipayment_leasing_rows key=label item=value}
                    <tr>
                        <th scope="row">{$label|escape:'htmlall':'UTF-8'}</th>
                        <td>{$value|escape:'htmlall':'UTF-8'}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {/if}
</section>
