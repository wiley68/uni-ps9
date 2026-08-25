<div class="panel">
  <div class="panel-heading"><i class="icon-cogs"></i> {l s='Системни настройки' d='Modules.Unipayment.Admin'}</div>
  {if !$unipayment_secret_readable}<div class="alert alert-danger">{l s='Съхраненият секрет не може да бъде прочетен. Моля, въведете го отново.' d='Modules.Unipayment.Admin'}</div>{/if}

  <form id="unipayment-settings-form" action="{$unipayment_form_action|escape:'htmlall':'UTF-8'}" method="post" class="form-horizontal">
    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_ENABLED_on">{l s='УниКредит покупки на Кредит' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_on" value="1"{if $unipayment_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_ENABLED" id="UNIPAYMENT_ENABLED_off" value="0"{if !$unipayment_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Дава възможност на Вашите клиенти да закупуват стока на изплащане с УниКредит.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_UNICID">{l s='Уникален идентификационен код на магазина Ви' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="text" name="UNIPAYMENT_UNICID" id="UNIPAYMENT_UNICID" value="{$unipayment_unicid|escape:'htmlall':'UTF-8'}" maxlength="36" required>
        <p class="help-block">{l s='Вашият уникален идентификационен код на магазина в системата на УниКредит.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3 required" for="UNIPAYMENT_SECRET">{l s='Секретен код на магазина Ви' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="password" name="UNIPAYMENT_SECRET" id="UNIPAYMENT_SECRET" value="" maxlength="64" autocomplete="new-password"{if !$unipayment_has_secret} required{/if}>
        <p class="help-block">{l s='Вашият секретен код на магазина в системата на УниКредит.' d='Modules.Unipayment.Admin'} {if $unipayment_has_secret}{l s='Оставете празно, за да запазите текущия секрет.' d='Modules.Unipayment.Admin'}{/if}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_ADVERTISING_ENABLED_on">{l s='Визуализиране на реклама' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_ADVERTISING_ENABLED" id="UNIPAYMENT_ADVERTISING_ENABLED_on" value="1"{if $unipayment_advertising_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ADVERTISING_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_ADVERTISING_ENABLED" id="UNIPAYMENT_ADVERTISING_ENABLED_off" value="0"{if !$unipayment_advertising_enabled} checked="checked"{/if}><label for="UNIPAYMENT_ADVERTISING_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Можете да включите или изключите рекламата на началната страница на магазина.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_DEBUG_ENABLED_on">{l s='Режим отстраняване на грешки' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <span class="switch prestashop-switch fixed-width-lg">
          <input type="radio" name="UNIPAYMENT_DEBUG_ENABLED" id="UNIPAYMENT_DEBUG_ENABLED_on" value="1"{if $unipayment_debug_enabled} checked="checked"{/if}><label for="UNIPAYMENT_DEBUG_ENABLED_on">{l s='Yes' d='Admin.Global'}</label>
          <input type="radio" name="UNIPAYMENT_DEBUG_ENABLED" id="UNIPAYMENT_DEBUG_ENABLED_off" value="0"{if !$unipayment_debug_enabled} checked="checked"{/if}><label for="UNIPAYMENT_DEBUG_ENABLED_off">{l s='No' d='Admin.Global'}</label><a class="slide-button btn"></a>
        </span>
        <p class="help-block">{l s='Изберете тази опция, ако искате да включите режим за отстраняване на грешки.' d='Modules.Unipayment.Admin'}</p>
        <p class="help-block">{l s='При включване се записват заявката и отговорът към SmartUCF при създаване на поръчка в журнален запис в базата данни (съхраняват се 3 месеца).' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_PRODUCT_BUTTON_ACTION">{l s='Бутон купи' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <select name="UNIPAYMENT_PRODUCT_BUTTON_ACTION" id="UNIPAYMENT_PRODUCT_BUTTON_ACTION">
          <option value="add_to_cart"{if $unipayment_product_button_action === 'add_to_cart'} selected="selected"{/if}>{l s='Добави в количката' d='Modules.Unipayment.Admin'}</option>
          <option value="buy"{if $unipayment_product_button_action === 'buy'} selected="selected"{/if}>{l s='Купи' d='Modules.Unipayment.Admin'}</option>
        </select>
        <p class="help-block">{l s='Поведение на вторичния бутон в модулния popup на продуктовата страница. „Добави в количката“ добавя продукта в количката. „Купи“ пренасочва към checkout с предварително избрано плащане на изплащане с УниКредит.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="UNIPAYMENT_BUTTON_TOP_SPACING">{l s='Свободно място над бутона' d='Modules.Unipayment.Admin'}</label>
      <div class="col-lg-9">
        <input type="number" class="fixed-width-sm" name="UNIPAYMENT_BUTTON_TOP_SPACING" id="UNIPAYMENT_BUTTON_TOP_SPACING" value="{$unipayment_button_top_spacing|escape:'htmlall':'UTF-8'}" min="0" max="200" step="1">
        <p class="help-block">{l s='Свободно място над бутона в px.' d='Modules.Unipayment.Admin'}</p>
      </div>
    </div>

  </form>

  <div class="panel-footer" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button type="submit" name="submitUnipaymentConfiguration" form="unipayment-settings-form" class="btn btn-primary"><i class="process-icon-save"></i> {l s='Запази настройките' d='Modules.Unipayment.Admin'}</button>
    <button type="button" name="submitUnipaymentRefresh" class="btn btn-default" disabled="disabled" title="{l s='Ще бъде налично след локалния shop cache (Phase 3).' d='Modules.Unipayment.Admin'}"><i class="icon-refresh"></i> {l s='Обнови данните от банката' d='Modules.Unipayment.Admin'}</button>
    <button type="button" name="submitUnipaymentDownloadJournal" class="btn btn-default" disabled="disabled" title="{l s='Ще бъде налично след SmartUCF диагностиката.' d='Modules.Unipayment.Admin'}"><i class="icon-download"></i> {l s='Изтегли журнал операции' d='Modules.Unipayment.Admin'}</button>
  </div>
  {if !$unipayment_cp_actions_available}
    <p class="help-block" style="margin-top:10px;">{l s='„Обнови данните от банката“ изисква Phase 3 shop cache. „Изтегли журнал операции“ изисква по-късна SmartUCF диагностика. Control Panel authentication client е наличен в Phase 2, но тези бутони остават деактивирани.' d='Modules.Unipayment.Admin'}</p>
  {/if}
</div>
