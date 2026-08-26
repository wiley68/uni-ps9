<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Advertising;

/**
 * Homepage reklama payload. Graphic URLs come from CP (R2/CDN), never from the module.
 */
final class HomepageAdvertisingPresenter
{
    /** @var HomepageAdvertisingGate */
    private $gate;

    public function __construct(?HomepageAdvertisingGate $gate = null)
    {
        $this->gate = $gate ?? new HomepageAdvertisingGate();
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>|null
     */
    public function present(array $shop, bool $isMobile, string $defaultLogoUrl): ?array
    {
        if (!$this->gate->allowsShop($shop)) {
            return null;
        }

        $pictureUrl = $this->httpUrl($shop['uni_picturem'] ?? '');
        $floatImageUrl = $isMobile ? $pictureUrl : $defaultLogoUrl;
        if ($floatImageUrl === '') {
            $floatImageUrl = $defaultLogoUrl;
        }

        return [
            'is_mobile' => $isMobile,
            'backurl' => $this->httpUrl($shop['uni_backurl'] ?? ''),
            'txt1' => $this->text($shop['uni_container_txt1'] ?? ''),
            'txt2' => $this->text($shop['uni_container_txt2'] ?? ''),
            'float_image_url' => $floatImageUrl,
            'picture_url' => $pictureUrl,
        ];
    }

    /** @param mixed $value */
    private function httpUrl($value): string
    {
        $url = trim((string) $value);

        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : '';
    }

    /** @param mixed $value */
    private function text($value): string
    {
        return trim(strip_tags((string) $value));
    }
}
