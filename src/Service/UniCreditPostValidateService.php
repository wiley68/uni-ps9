<?php

/**
 * Copyright since 2024 Ilko Ivanov
 *
 * @author    Ilko Ivanov
 * @copyright Ilko Ivanov
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *
 * След validateOrder: addorders.php, sucfOnlineSessionStart, имейл при uni_proces2 (логика от PS 1.7 validation).
 */

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Service;

use Cart;
use Configuration;
use Currency;
use Customer;
use Mail;
use PrestaShop\Module\Unipayment\Config\UnipaymentConfig;
use PrestaShop\Module\Unipayment\Form\UnipaymentConfigurationDataConfiguration as UnipaymentConf;

final class UniCreditPostValidateService
{
    private const CLIENT_PEM_PASSPHRASE = '1234';

    /**
     * @param string $moduleRootPath абсолютен път към корена на модула (keys, mails)
     */
    public function __construct(
        private readonly string $moduleRootPath,
    ) {
    }

    /**
     * @param array<string, mixed>  $paramsuni От getparameters (кеш)
     * @param array<string, string> $calc      uni_mesecna, uni_gpr, uni_parva, uni_glp, uni_vnoski, uni_kop
     * @param array<string, string> $formDisplay Полета от формата (име, ЕГН, тел. от cookie)
     *
     * @return array{uni_proces1: int, uni_application: string, uni_api: string, uni_proces2: int, uniresult_b64: string}
     */
    public function run(
        int $orderId,
        Cart $cart,
        Customer $customer,
        Currency $currency,
        array $paramsuni,
        array $calc,
        array $formDisplay,
        string $customerPhone,
        string $shippingAddress,
        string $shippingCity,
        string $shippingCounty,
        string $billingAddress,
        string $billingCity,
        string $billingCounty,
    ): array {
        $out = [
            'uni_proces1' => 0,
            'uni_application' => '',
            'uni_api' => '',
            'uni_proces2' => 0,
            'uniresult_b64' => '',
        ];

        if ($orderId <= 0) {
            return $out;
        }

        $out['uni_proces1'] = (int) ($paramsuni['uni_proces1'] ?? 0);
        $out['uni_proces2'] = (int) ($paramsuni['uni_proces2'] ?? 0);

        $uni_testenv = (int) ($paramsuni['uni_testenv'] ?? 0);
        if ($uni_testenv === 1) {
            $uni_service = (string) ($paramsuni['uni_test_service'] ?? '');
            $uni_application = (string) ($paramsuni['uni_test_application'] ?? '');
        } else {
            $uni_service = (string) ($paramsuni['uni_production_service'] ?? '');
            $uni_application = (string) ($paramsuni['uni_production_application'] ?? '');
        }
        $out['uni_application'] = $uni_application;

        $uni_user = htmlspecialchars_decode((string) ($paramsuni['uni_user'] ?? ''), ENT_QUOTES);
        $uni_password = htmlspecialchars_decode((string) ($paramsuni['uni_password'] ?? ''), ENT_QUOTES);

        $uni_currency_code = (string) $currency->iso_code;
        $uni_eur = (int) ($paramsuni['uni_eur'] ?? 0);
        $uni_currency_code_send = 'BGN';

        $uni_total = number_format((float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS), 2, '.', '');
        switch ($uni_eur) {
            case 1:
                $uni_currency_code_send = 'BGN';
                if ($uni_currency_code === 'EUR') {
                    $uni_total = number_format((float) $uni_total * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                }
                break;
            case 2:
            case 3:
                $uni_currency_code_send = 'EUR';
                if ($uni_currency_code === 'BGN') {
                    $uni_total = number_format((float) $uni_total / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                }
                break;
        }

        $products = $cart->getProducts(true);
        $uni_items = [];
        $ident = 0;
        foreach ($products as $product) {
            $uni_items[$ident] = [
                'name' => $this->stripQuotes((string) ($product['name'] ?? '')),
                'code' => (string) ($product['id_product'] ?? ''),
                'type' => (string) ($product['id_category_default'] ?? ''),
                'count' => (int) ($product['quantity'] ?? 0),
                'singlePrice' => number_format((float) ($product['price_wt'] ?? 0), 2, '.', ''),
            ];
            switch ($uni_eur) {
                case 1:
                    if ($uni_currency_code === 'EUR') {
                        $uni_items[$ident]['singlePrice'] = number_format(
                            (float) ($product['price_wt'] ?? 0) * UnipaymentConfig::EUR_BGN_RATE,
                            2,
                            '.',
                            ''
                        );
                    }
                    break;
                case 2:
                case 3:
                    if ($uni_currency_code === 'BGN') {
                        $uni_items[$ident]['singlePrice'] = number_format(
                            (float) ($product['price_wt'] ?? 0) / UnipaymentConfig::EUR_BGN_RATE,
                            2,
                            '.',
                            ''
                        );
                    }
                    break;
            }
            ++$ident;
        }

        $uni_fname = $this->stripQuotes((string) $customer->firstname);
        $uni_lname = $this->stripQuotes((string) $customer->lastname);
        $uni_email = $this->stripQuotes((string) $customer->email);
        $uni_phone_clean = $this->stripQuotes($customerPhone);

        $uni_post = [
            'orderId' => $orderId,
            'orderTotal' => $uni_total,
            'vnoska' => $calc['uni_mesecna'],
            'gpr' => $calc['uni_gpr'],
            'glp' => $calc['uni_glp'],
            'vnoski' => $calc['uni_vnoski'],
            'parva' => $calc['uni_parva'],
            'devices' => $this->resolveDeviceLabel(),
            'currency' => $uni_currency_code_send,
            'customer' => [
                'firstName' => $uni_fname,
                'lastName' => $uni_lname,
                'email' => $uni_email,
                'phone' => $uni_phone_clean,
                'billingAddress' => $this->stripQuotes($billingAddress),
                'billingCity' => $this->stripQuotes($billingCity),
                'billingCounty' => $this->stripQuotes($billingCounty),
                'deliveryAddress' => $this->stripQuotes($shippingAddress),
                'deliveryCity' => $this->stripQuotes($shippingCity),
                'deliveryCounty' => $this->stripQuotes($shippingCounty),
            ],
            'items' => $uni_items,
        ];

        $cid = (string) (Configuration::get(UnipaymentConf::UNIPAYMENT_UNICID) ?: '');
        $addUrl = rtrim(UnipaymentConfig::LIVE_URL, '/') . '/function/addorders.php?cid=' . rawurlencode($cid);

        $addCh = curl_init($addUrl);
        curl_setopt_array($addCh, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($uni_post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_TIMEOUT => 8,
        ]);
        $addRaw = curl_exec($addCh);
        unset($addCh);

        $paramsuniadd = is_string($addRaw) ? json_decode($addRaw, true) : null;
        if (!is_array($paramsuniadd)) {
            $paramsuniadd = [];
        }

        $uni_data = [
            'user' => $uni_user,
            'pass' => $uni_password,
            'orderNo' => $orderId,
            'clientFirstName' => $uni_fname,
            'clientLastName' => $uni_lname,
            'clientPhone' => $uni_phone_clean,
            'clientEmail' => $uni_email,
            'clientDeliveryAddress' => $this->stripQuotes($shippingAddress),
            'onlineProductCode' => $calc['uni_kop'],
            'totalPrice' => $uni_total,
            'initialPayment' => $calc['uni_parva'],
            'installmentCount' => $calc['uni_vnoski'],
            'monthlyPayment' => $calc['uni_mesecna'],
            'items' => $uni_items,
        ];

        $uni_api = '';
        $uni_proces1 = (int) ($paramsuni['uni_proces1'] ?? 0);
        if ($uni_proces1 > 0 && (($paramsuniadd['status'] ?? '') === 'Yes')) {
            $keyFile = null;
            $certFile = null;
            if (($paramsuni['uni_sertificat'] ?? '') === 'Yes') {
                $paths = $this->ensureClientPemFiles();
                if ($paths !== null) {
                    $keyFile = $paths['key'];
                    $certFile = $paths['cert'];
                }
            }

            $sessionUrl = $uni_service . 'sucfOnlineSessionStart';
            $opts = [
                CURLOPT_URL => $sessionUrl,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => (string) json_encode($uni_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'cache-control: no-cache',
                ],
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ];
            if (defined('CURL_SSLVERSION_TLSv1_2')) {
                $opts[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
            } else {
                $opts[CURLOPT_SSLVERSION] = 6;
            }
            if ($keyFile !== null && $certFile !== null) {
                $opts[CURLOPT_SSLKEY] = $keyFile;
                $opts[CURLOPT_SSLKEYPASSWD] = self::CLIENT_PEM_PASSPHRASE;
                $opts[CURLOPT_SSLCERT] = $certFile;
                $opts[CURLOPT_SSLCERTPASSWD] = self::CLIENT_PEM_PASSPHRASE;
            }

            $apiCh = curl_init();
            curl_setopt_array($apiCh, $opts);
            $responseapi = curl_exec($apiCh);
            $curlErr = curl_error($apiCh);
            unset($apiCh);

            $api_obj = is_string($responseapi) ? json_decode($responseapi) : null;
            if (is_object($api_obj) && !empty($api_obj->sucfOnlineSessionID)) {
                $uni_api = (string) $api_obj->sucfOnlineSessionID;
            }
            $out['uni_api'] = $uni_api;

            $this->maybeWriteDebug((int) Configuration::get(UnipaymentConf::UNIPAYMENT_DEBUG), $curlErr, $responseapi, $uni_data);
        }

        $resultHtml = null;
        if ((int) ($paramsuni['uni_proces2'] ?? 0) === 1) {
            $resultHtml = $this->buildProcess2EmailBody(
                $orderId,
                $formDisplay,
                $calc,
                $uni_items,
                $uni_currency_code,
                $uni_eur,
                $uni_total,
                $shippingAddress
            );
            $this->sendProcess2Mail($paramsuni, $formDisplay, (string) $resultHtml);
        }

        if ($resultHtml !== null) {
            $out['uniresult_b64'] = base64_encode((string) $resultHtml);
        }

        return $out;
    }

    /** Премахва типографски кавички от низ (legacy съвместимост). */
    private function stripQuotes(string $s): string
    {
        return str_replace(["'", "'"], '', $s);
    }

    /** Етикет за устройство за банков payload (мобилен / настолен). */
    private function resolveDeviceLabel(): string
    {
        $useragent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $re1 = '/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i';
        $re2 = '/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i';

        if (preg_match($re1, $useragent) || preg_match($re2, substr($useragent, 0, 4))) {
            return 'МОБИЛЕН ТЕЛЕФОН';
        }

        return 'НАСТОЛЕН КОМПЮТЪР';
    }

    /**
     * @param array<int, array<string, mixed>> $uni_items
     * @param array<string, string>            $formDisplay
     */
    private function buildProcess2EmailBody(
        int $orderId,
        array $formDisplay,
        array $calc,
        array $uni_items,
        string $uni_currency_code,
        int $uni_eur,
        string $uni_total,
        string $uni_shipping_address,
    ): string {
        $uni_fname_get = $formDisplay['uni_fname'] ?? '';
        $uni_lname_get = $formDisplay['uni_lname'] ?? '';
        $uni_egn_get = $formDisplay['uni_egn'] ?? '';
        $uni_phone_get = $formDisplay['uni_phone'] ?? '';
        $uni_phone2_get = $formDisplay['uni_phone2'] ?? '';
        $uni_email_get = $formDisplay['uni_email'] ?? '';
        $uni_description_get = $formDisplay['uni_description'] ?? '';
        $uni_kop = $calc['uni_kop'] ?? '';
        $uni_mesecna = $calc['uni_mesecna'] ?? '';
        $uni_gpr = $calc['uni_gpr'] ?? '';
        $uni_parva = $calc['uni_parva'] ?? '';
        $uni_vnoski = $calc['uni_vnoski'] ?? '';

        $result = '<span class="uni_result">Резултат от заявката.</span><br /><br />';
        $result .= '<span class="uni_subresult">Заявката е изпратена успешно.</span><br /><br />';
        $result .= 'Заявка за лизинг с UniCredit.<br /><br />';
        $result .= 'Поръчка №: ' . $orderId . '<br />';
        $result .= 'Име: ' . $uni_fname_get . '<br />';
        $result .= 'Фамилия: ' . $uni_lname_get . '<br />';
        $result .= 'ЕГН: ' . $uni_egn_get . '<br />';
        $result .= 'Телефон: ' . $uni_phone_get . '<br />';
        $result .= 'Втори телефон: ' . $uni_phone2_get . '<br />';
        $result .= 'E-Mail: ' . $uni_email_get . '<br />';
        $result .= 'Адрес за доставка: ' . $uni_shipping_address . '<br />';
        $result .= 'KOP: ' . $uni_kop . '<br />';
        $result .= 'Коментар: ' . $uni_description_get . '<br />';

        foreach ($uni_items as $item) {
            $itemSinglePrice = (string) ($item['singlePrice'] ?? '');
            switch ($uni_eur) {
                case 1:
                    if ($uni_currency_code === 'EUR') {
                        $itemSinglePrice = number_format((float) $itemSinglePrice * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                    }
                    break;
                case 2:
                case 3:
                    if ($uni_currency_code === 'BGN') {
                        $itemSinglePrice = number_format((float) $itemSinglePrice / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                    }
                    break;
            }
            $result .= 'Продукт ИД: ' . ($item['code'] ?? '') . ' , Продукт: ' . ($item['name'] ?? '') . ' , Кол.: ' . ($item['count'] ?? '') . ' , Ед. цена: ' . $itemSinglePrice . '<br />';
        }

        $uni_obshta = number_format((float) $uni_vnoski * (float) $uni_mesecna, 2, '.', '');
        $uni_total_second = '0';
        $uni_mesecna_second = '0';
        $uni_obshta_second = '0';
        $uni_sign = 'лева';
        $uni_sign_second = 'евро';
        switch ($uni_eur) {
            case 0:
                break;
            case 1:
                $uni_total_second = number_format((float) $uni_total / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_mesecna_second = number_format((float) $uni_mesecna / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_obshta_second = number_format((float) $uni_obshta / UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                break;
            case 2:
                $uni_total_second = number_format((float) $uni_total * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_mesecna_second = number_format((float) $uni_mesecna * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_obshta_second = number_format((float) $uni_obshta * UnipaymentConfig::EUR_BGN_RATE, 2, '.', '');
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
            case 3:
                $uni_sign = 'евро';
                $uni_sign_second = 'лева';
                break;
        }

        if ($uni_total_second === '0' || (float) $uni_total_second === 0.0) {
            $result .= 'Цена на стоките (' . $uni_sign . '): ' . $uni_total . '<br />';
        } else {
            $result .= 'Цена на стоките (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_total . ' / ' . $uni_total_second . '<br />';
        }
        $result .= 'Първоначална вноска (' . $uni_sign . '): ' . $uni_parva . '<br />';
        $result .= 'Брой погасителни вноски: ' . $uni_vnoski . '<br />';
        if ($uni_mesecna_second === '0' || (float) $uni_mesecna_second === 0.0) {
            $result .= 'Месечна вноска (' . $uni_sign . '): ' . $uni_mesecna . '<br />';
        } else {
            $result .= 'Месечна вноска (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_mesecna . ' / ' . $uni_mesecna_second . '<br />';
        }
        $result .= 'ГПР (%): ' . $uni_gpr . '<br />';
        if ($uni_obshta_second === '0' || (float) $uni_obshta_second === 0.0) {
            $result .= 'Обща дължима сума от потребителя (' . $uni_sign . '): ' . $uni_obshta . '<br />';
        } else {
            $result .= 'Обща дължима сума от потребителя (' . $uni_sign . '/' . $uni_sign_second . '): ' . $uni_obshta . ' / ' . $uni_obshta_second . '<br />';
        }
        $result .= '<strong>Очаквайте контакт за потвърждаване на направената от Вас заявка.</strong><br />';
        $result .= 'Можете да продължите с разглеждането на нашия магазин.';

        return $result;
    }

    /**
     * @param array<string, mixed> $paramsuni
     * @param array<string, string> $formDisplay
     */
    private function sendProcess2Mail(array $paramsuni, array $formDisplay, string $resultHtml): void
    {
        $toName = (string) Configuration::get('PS_SHOP_NAME');
        $toEmailAdmin = (string) Configuration::get('PS_SHOP_EMAIL');
        $toEmailOther = (string) ($paramsuni['uni_email'] ?? '');
        $uni_email_get = (string) ($formDisplay['uni_email'] ?? '');

        if ($toEmailOther !== '') {
            $tomlStr = str_replace(' ', '', $toEmailAdmin . ',' . $toEmailOther . ',' . $uni_email_get);
        } else {
            $tomlStr = str_replace(' ', '', $toEmailAdmin . ',' . $uni_email_get);
        }
        $toml = array_values(array_unique(array_filter(explode(',', $tomlStr))));

        if ($toml === []) {
            return;
        }

        $templatePath = $this->moduleRootPath . '/mails/';
        if (!is_dir($templatePath)) {
            return;
        }

        $subject = 'Заявка за лизинг с UniCredit.';
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $resultText = trim((string) preg_replace('/\n{3,}/', "\n\n", html_entity_decode((string) strip_tags(str_replace(['<br />', '<br/>', '<br>'], "\n", $resultHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        Mail::send(
            $idLang,
            'ordersend',
            $subject,
            [
                '{email}' => $toEmailAdmin,
                '{message}' => $resultHtml,
                '{message_text}' => $resultText,
            ],
            $toml,
            null,
            $toEmailAdmin,
            $toName,
            null,
            null,
            $templatePath,
            false,
            null
        );
    }

    /**
     * @return array{key: string, cert: string}|null
     */
    private function ensureClientPemFiles(): ?array
    {
        $base = rtrim(UnipaymentConfig::LIVE_URL, '/');
        $keyPath = $this->moduleRootPath . '/keys/avalon_private_key.pem';
        $certPath = $this->moduleRootPath . '/keys/avalon_cert.pem';

        if (
            !$this->downloadUrlToFile($base . '/calculators/key/avalon_private_key.pem', $keyPath)
            || !$this->downloadUrlToFile($base . '/calculators/key/avalon_cert.pem', $certPath)
        ) {
            return null;
        }

        return ['key' => $keyPath, 'cert' => $certPath];
    }

    /** Изтегля файл само от {@see UnipaymentConfig::LIVE_URL}. */
    private function downloadUrlToFile(string $url, string $destination): bool
    {
        $allowedPrefix = rtrim(UnipaymentConfig::LIVE_URL, '/');
        if (!str_starts_with($url, $allowedPrefix)) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($data === false || $code !== 200) {
            return false;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $data) !== false;
    }

    /**
     * При UNIPAYMENT_DEBUG записва фрагмент в keys/uni_debug.json.
     *
     * @param array<string, mixed> $uni_data
     */
    private function maybeWriteDebug(int $debug, string $curlErr, mixed $responseapi, array $uni_data): void
    {
        if ($debug !== 1) {
            return;
        }

        $path = $this->moduleRootPath . '/keys/uni_debug.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $chunk = 'err: ' . $curlErr . PHP_EOL;
        $chunk .= 'response: ' . (is_string($responseapi) ? $responseapi : '') . PHP_EOL;
        $chunk .= 'request: ' . json_encode($uni_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL . "##########\n";
        file_put_contents($path, $chunk);
    }
}
