<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

final class NativePrestaShopOrderGateway implements PrestaShopOrderGatewayInterface
{
    private \PaymentModule $module;
    private \Context $context;

    public function __construct(\PaymentModule $module, \Context $context)
    {
        $this->module = $module;
        $this->context = $context;
    }

    public function create(ValidatedPaymentRequest $request, array $shop = []): CreatedOrder
    {
        $cart = $this->context->cart;
        $customer = $this->context->customer;
        $existingOrderId = (int) \Order::getIdByCartId((int) $cart->id);
        if ($existingOrderId > 0) {
            return $this->load($existingOrderId);
        }

        $process2 = $shop !== [] && ShopConfigurationFlags::isProcess2($shop);
        $amount = round((float) $cart->getOrderTotal(true, \Cart::BOTH), 2);
        $extraVars = [];
        if ($shop !== [] && !$process2) {
            $extraVars = (new LeasingOrderEmailPresenter())->mailExtraVarsFromRequest($request, $shop);
            DeferredOrderMailQueue::start();
        }

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) \Configuration::get(OrderStateInstaller::AWAITING),
                $amount,
                $this->module->displayName,
                null,
                $extraVars,
                (int) $cart->id_currency,
                false,
                (string) $customer->secure_key
            );
        } catch (\Throwable $exception) {
            $existingOrderId = (int) \Order::getIdByCartId((int) $cart->id);
            if ($existingOrderId <= 0) {
                if (!$process2) {
                    DeferredOrderMailQueue::discard();
                }
                throw $exception;
            }

            return $this->load($existingOrderId);
        }

        if ((int) $this->module->currentOrder <= 0) {
            throw new \RuntimeException('PrestaShop did not create the financing order.');
        }

        return $this->load((int) $this->module->currentOrder);
    }

    public function load(int $idOrder): CreatedOrder
    {
        $order = new \Order($idOrder);
        if (!\Validate::isLoadedObject($order)) {
            throw new \RuntimeException('The financing order could not be loaded.');
        }
        $currency = new \Currency((int) $order->id_currency);
        $customer = new \Customer((int) $order->id_customer);
        $invoice = new \Address((int) $order->id_address_invoice);
        $delivery = new \Address((int) $order->id_address_delivery);
        $lines = [];
        foreach ($order->getProducts() as $row) {
            $lines[] = [
                'id_product' => (int) $row['product_id'],
                'id_product_attribute' => (int) $row['product_attribute_id'],
                'name' => (string) $row['product_name'],
                'quantity' => (int) $row['product_quantity'],
                'total' => (float) $row['total_price_tax_incl'],
            ];
        }

        return new CreatedOrder(
            (int) $order->id,
            (string) $order->reference,
            (float) $order->total_paid_tax_incl,
            (string) $currency->iso_code,
            (int) $currency->id,
            [
                'first_name' => (string) $invoice->firstname,
                'last_name' => (string) $invoice->lastname,
                'email' => (string) $customer->email,
                'phone' => (string) ($invoice->phone_mobile ?: $invoice->phone),
            ],
            ['invoice' => $this->address($invoice), 'delivery' => $this->address($delivery)],
            $lines
        );
    }

    public function markFailed(int $idOrder): void
    {
        $order = new \Order($idOrder);
        if (\Validate::isLoadedObject($order)) {
            $order->setCurrentState((int) \Configuration::get(OrderStateInstaller::FAILED));
        }
    }

    public function markAwaiting(int $idOrder): void
    {
        $order = new \Order($idOrder);
        if (\Validate::isLoadedObject($order)) {
            $order->setCurrentState((int) \Configuration::get(OrderStateInstaller::AWAITING));
        }
    }

    /** @return array<string, string> */
    private function address(\Address $address): array
    {
        return [
            'address1' => (string) $address->address1,
            'address2' => (string) $address->address2,
            'postcode' => (string) $address->postcode,
            'city' => (string) $address->city,
            'country' => (string) \Country::getNameById((int) $this->context->language->id, (int) $address->id_country),
        ];
    }
}
