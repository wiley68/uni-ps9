<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Maps normalized lifecycle results to popup JSON fields (transport adapter).
 */
final class PostControlPanelLifecyclePopupMapper
{
    /**
     * @param array<string, mixed> $response
     */
    public static function apply(array &$response, PostControlPanelLifecycleResult $result): void
    {
        if ($result->emailError() !== null) {
            $response['email_error'] = $result->emailError();
        }
        if ($result->postOrderError() !== null) {
            $response['post_order_error'] = $result->postOrderError();
        }

        switch ($result->outcome()) {
            case PostControlPanelLifecycleResult::OUTCOME_SMARTUCF_CREATED:
                if ($result->redirectUrl() !== '') {
                    $response['redirect_url'] = $result->redirectUrl();
                }
                $response['step'] = 'order_created';
                break;
            case PostControlPanelLifecycleResult::OUTCOME_SMARTUCF_PROCESSING:
                $response['step'] = 'processing';
                $response['message'] = $result->customerMessage();
                break;
            case PostControlPanelLifecycleResult::OUTCOME_SMARTUCF_OUTCOME_UNKNOWN:
                $response['step'] = 'outcome_unknown';
                $response['smartucf_error'] = $result->customerMessage();
                break;
            case PostControlPanelLifecycleResult::OUTCOME_SMARTUCF_FAILED:
                $response['smartucf_error'] = $result->customerMessage();
                break;
            case PostControlPanelLifecycleResult::OUTCOME_PROCESS2:
                // redirect_url is assigned by the controller (confirmation URL builder).
                break;
            case PostControlPanelLifecycleResult::OUTCOME_SNAPSHOT_MISSING:
            case PostControlPanelLifecycleResult::OUTCOME_POST_ORDER_FAILURE:
                break;
        }
    }
}
