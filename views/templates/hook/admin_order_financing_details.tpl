<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-header-title">{l s='УниКредит — кредитна заявка' d='Modules.Unipayment.Admin'}</h3>
    </div>
    <div class="card-body">
        {if isset($unipayment_leasing_rows) && $unipayment_leasing_rows|@count > 0}
            <table class="table table-sm table-bordered mb-0">
                <tbody>
                    {foreach from=$unipayment_leasing_rows key=label item=value}
                        <tr>
                            <th scope="row" style="width: 35%;">{$label|escape:'htmlall':'UTF-8'}</th>
                            <td>{$value|escape:'htmlall':'UTF-8'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}

    </div>
</div>
