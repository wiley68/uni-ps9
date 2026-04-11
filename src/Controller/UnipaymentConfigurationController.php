<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Controller;

use Module;
use PrestaShop\Module\Unipayment\Service\KopMappingService;
use PrestaShop\Module\Unipayment\Service\UniCreditGetCoeffService;
use PrestaShop\PrestaShop\Core\Form\Handler;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office: Symfony форма за настройки + AJAX за KOP мапинга и refresh от банката.
 *
 * PrestaShop 9: {@see PrestaShopAdminController} вместо остарелия FrameworkBundleAdminController.
 */
class UnipaymentConfigurationController extends PrestaShopAdminController
{
    public function __construct(
        private readonly Handler $unipaymentConfigurationFormHandler,
        private readonly KopMappingService $kopMappingService,
    ) {}

    /**
     * Главна страница на конфигурацията (форма + таблица KOP).
     */
    public function index(Request $request): Response
    {
        $textForm = $this->unipaymentConfigurationFormHandler->getForm();
        $textForm->handleRequest($request);

        if ($textForm->isSubmitted() && $textForm->isValid()) {
            $errors = $this->unipaymentConfigurationFormHandler->save($textForm->getData());

            if (empty($errors)) {
                $this->addFlash(
                    'success',
                    $this->trans('Settings updated successfully.', [], 'Modules.Unipayment.Admin')
                );

                return $this->redirectToRoute('unipayment_configuration_form');
            }

            $this->flashPlainErrors($errors);
        }

        return $this->render('@Modules/unipayment/views/templates/admin/form.html.twig', [
            'unipaymentConfigurationForm' => $textForm->createView(),
            'kopMappings' => $this->kopMappingService->getMappings(),
            'kopSaveUrl' => $this->generateUrl('unipayment_kop_mapping_save'),
            'kopRefreshUrl' => $this->generateUrl('unipayment_kop_mapping_refresh'),
        ]);
    }

    /**
     * AJAX запис на редове от админ таблицата (валидация + DB мапинг).
     */
    public function saveKopMapping(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $rows = is_array($payload) && isset($payload['uni_categories_kop']) && is_array($payload['uni_categories_kop'])
            ? $payload['uni_categories_kop']
            : [];

        $validationErrors = $this->kopMappingService->validateMappings($rows);
        if (!empty($validationErrors)) {
            return new JsonResponse([
                'result' => 'error',
                'errors' => $validationErrors,
            ], 422);
        }

        $isSaved = $this->kopMappingService->saveMappings($rows);

        return new JsonResponse([
            'result' => $isSaved ? 'success' : 'error',
            'errors' => $isSaved ? [] : [
                $this->trans('Could not save KOP mappings.', [], 'Modules.Unipayment.Admin'),
            ],
        ], $isSaved ? 200 : 500);
    }

    /**
     * AJAX: нулира stats в DB, опреснява кеша на параметрите от getparameters, чисти стар getCoeff кеш (DB).
     */
    public function refreshKopMapping(): JsonResponse
    {
        $coeffPurged = $this->purgeUniCoeffCacheOlderThanToday();

        $kopOk = $this->kopMappingService->refreshMappings();
        if (!$kopOk) {
            return new JsonResponse([
                'result' => 'error',
                'kop_refreshed' => false,
                'params_refreshed' => false,
                'coeff_purged' => $coeffPurged,
                'errors' => [
                    $this->trans('Could not refresh KOP mapping.', [], 'Modules.Unipayment.Admin'),
                ],
            ], 500);
        }

        $paramsOk = false;
        $module = Module::getInstanceByName('unipayment');
        if ($module instanceof \UniPayment && method_exists($module, 'refreshCachedUniParamsFromApi')) {
            $paramsOk = $module->refreshCachedUniParamsFromApi();
        }

        if (!$paramsOk) {
            return new JsonResponse([
                'result' => 'partial',
                'kop_refreshed' => true,
                'params_refreshed' => false,
                'coeff_purged' => $coeffPurged,
                'errors' => [
                    $this->trans(
                        'KOP mapping was updated, but the bank parameters cache could not be refreshed. Check UNIPAYMENT_UNICID and network connectivity.',
                        [],
                        'Modules.Unipayment.Admin'
                    ),
                ],
            ], 200);
        }

        return new JsonResponse([
            'result' => 'success',
            'kop_refreshed' => true,
            'params_refreshed' => true,
            'coeff_purged' => $coeffPurged,
        ], 200);
    }

    /** @return int брой изтрити coeff редове в API кеш таблицата преди началото на днешния ден */
    private function purgeUniCoeffCacheOlderThanToday(): int
    {
        $module = Module::getInstanceByName('unipayment');
        if (!$module instanceof \UniPayment) {
            return 0;
        }
        $root = method_exists($module, 'getLocalPath') ? $module->getLocalPath() : (_PS_MODULE_DIR_ . 'unipayment/');
        $root = rtrim((string) $root, '/\\');

        return (new UniCreditGetCoeffService($root))->purgeCoeffCacheFilesOlderThanToday();
    }

    /**
     * Съобщения за грешки от {@see Handler::save()} (низове от валидация на конфигурацията).
     *
     * @param array<int, string> $errorMessages
     */
    private function flashPlainErrors(array $errorMessages): void
    {
        foreach ($errorMessages as $error) {
            $this->addFlash('error', $error);
        }
    }
}
