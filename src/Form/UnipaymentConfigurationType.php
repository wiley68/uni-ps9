<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Полета за основните ключове UNIPAYMENT_* в конфигурацията на модула.
 */
class UnipaymentConfigurationType extends TranslatorAwareType
{
    /**
     * @see \Symfony\Component\Form\FormTypeInterface::buildForm()
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /*
         * Използвайте директно $this->trans('...', 'Modules.Unipayment.Admin', []) с литерални низове.
         * Екстракторът за „Интернационализация → Преводи“ не вижда низове през closure/променливи.
         */
        $builder
            ->add(
                'UNIPAYMENT_STATUS',
                SwitchType::class,
                [
                    'label' => $this->trans('UniCredit purchases on credit', 'Modules.Unipayment.Admin', []),
                    'help' => $this->trans(
                        'Allows your customers to buy goods on installment with UniCredit.',
                        'Modules.Unipayment.Admin',
                        []
                    ),
                ]
            )
            ->add('UNIPAYMENT_UNICID', TextType::class, [
                'label' => $this->trans('Unique store identification code', 'Modules.Unipayment.Admin', []),
                'help' => $this->trans(
                    'Your store unique identification code in the UniCredit system.',
                    'Modules.Unipayment.Admin',
                    []
                ),
                'required' => true,
            ])
            ->add(
                'UNIPAYMENT_REKLAMA',
                SwitchType::class,
                [
                    'label' => $this->trans('Show promotional widget', 'Modules.Unipayment.Admin', []),
                    'help' => $this->trans(
                        'Enable or disable the promotional widget on the store home page.',
                        'Modules.Unipayment.Admin',
                        []
                    ),
                ]
            )
            ->add(
                'UNIPAYMENT_CART',
                SwitchType::class,
                [
                    'label' => $this->trans('Direct buy on installment', 'Modules.Unipayment.Admin', []),
                    'help' => $this->trans(
                        'If enabled, clicking the calculator on the product page adds the product to the cart and sends the customer to checkout with UniCredit pre-selected as payment method.',
                        'Modules.Unipayment.Admin',
                        []
                    ),
                ]
            )
            ->add(
                'UNIPAYMENT_DEBUG',
                SwitchType::class,
                [
                    'label' => $this->trans('Debug mode', 'Modules.Unipayment.Admin', []),
                    'help' => $this->trans(
                        'Enable this option to turn on debug mode.',
                        'Modules.Unipayment.Admin',
                        []
                    ),
                ]
            )
            ->add('UNIPAYMENT_GAP', NumberType::class, [
                'label' => $this->trans('Spacing above the button', 'Modules.Unipayment.Admin', []),
                'help' => $this->trans('Extra space above the button, in pixels.', 'Modules.Unipayment.Admin', []),
                'required' => false,
                'empty_data' => '0',
            ]);
    }
}
