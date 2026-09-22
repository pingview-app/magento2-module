<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * What this store looks like from the customer's side: the addresses a buyer
 * walks through, the currencies they can switch to, the payment methods on
 * offer.
 *
 * Only the platform knows these. An external probe pointed at the home page
 * cannot find the checkout, and a crawler guessing `/checkout` is guessing.
 * That is why the profile is reported from inside the store (CT-PARITY
 * monitored-pages, checkout-journey).
 *
 * Everything here is best effort: a store with no products, a disabled catalog
 * module or an exotic URL rewrite must leave the panel intact, so each lookup
 * fails closed to "not found" instead of throwing.
 */
final class ShopProfile
{
    public function __construct(
        private readonly StoreManagerInterface $stores,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ProductCollectionFactory $products,
        private readonly CategoryCollectionFactory $categories,
        private readonly PaymentHelper $payments,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The payload for POST /shop-profile.
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $target): array
    {
        $urls = array_filter([
            'productUrl' => $this->productUrl(),
            'cartUrl' => $this->frontendUrl('checkout/cart'),
            'checkoutUrl' => $this->frontendUrl('checkout'),
        ]);

        return array_filter([
            'target' => $target,
            'platform' => 'magento',
            'platformVersion' => $this->productMetadata->getVersion(),
            'urls' => $urls ?: null,
            'locales' => $this->locales() ?: null,
            'paymentMethods' => $this->paymentMethods() ?: null,
            'currencies' => $this->currencies() ?: null,
        ], static fn($value): bool => $value !== null);
    }

    /**
     * Customer-facing addresses worth their own monitor, in the order a buyer
     * meets them. The cart and the checkout are checked by default because
     * that is where a broken deploy stops revenue; a category and a product
     * are offered but not preselected.
     *
     * @return array<int, array{key: string, label: string, url: string, default_checked: bool}>
     */
    public function monitorablePages(): array
    {
        $pages = [
            ['key' => 'cart', 'label' => 'Cart', 'url' => $this->frontendUrl('checkout/cart'), 'default_checked' => true],
            ['key' => 'checkout', 'label' => 'Checkout', 'url' => $this->frontendUrl('checkout'), 'default_checked' => true],
            ['key' => 'category', 'label' => 'Category', 'url' => $this->categoryUrl(), 'default_checked' => false],
            ['key' => 'product', 'label' => 'Product', 'url' => $this->productUrl(), 'default_checked' => false],
        ];

        return array_values(array_filter($pages, static fn(array $page): bool => $page['url'] !== null));
    }

    /**
     * Payload identity, so a daily resend only happens when the store actually
     * changed. An unconditional resend restarts the backend's checkout
     * verification every day for a store that did not move.
     */
    public function fingerprint(string $target): string
    {
        return sha1(json_encode($this->toPayload($target), JSON_THROW_ON_ERROR));
    }

    /**
     * The address customers actually use, with a trailing slash: the monitor
     * target and the "store moved" comparison. Every caller goes through here
     * so the target and the pages reported in the profile come from the same
     * store view (see frontendUrl() for why not getStore()).
     */
    public function storeUrl(): string
    {
        $base = $this->frontendUrl('');

        return ($base ?? rtrim((string)$this->stores->getStore()->getBaseUrl(), '/')) . '/';
    }

    /**
     * A customer-facing address, built from the store's link base URL.
     *
     * Every URL helper Magento offers — `Store::getUrl()`, `Product::getProductUrl()`,
     * `Category::getUrl()` — resolves `UrlInterface`, which inside adminhtml is the
     * BACK-OFFICE builder. They answered `https://shop/admin/checkout/cart/index/key/<secret>`:
     * the module then offered a login-walled route as a page to monitor and shipped
     * the admin secret key to the API with it. The base URL carries no area and no key.
     */
    private function frontendUrl(string $path): ?string
    {
        try {
            // The default store view, not getStore(): inside adminhtml that is
            // the admin store, whose base URL is the back-office one.
            $store = $this->stores->getDefaultStoreView();
            if ($store === null) {
                return null;
            }

            $base = rtrim((string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');
            $path = ltrim($path, '/');

            return $base === '' ? null : rtrim($base . '/' . $path, '/');
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not build a frontend URL', ['path' => $path, 'exception' => $error]);
            return null;
        }
    }

    private function productUrl(): ?string
    {
        try {
            $collection = $this->products->create();
            $collection->addAttributeToSelect('url_key')
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter('visibility', ['in' => [2, 4]])
                ->setPageSize(1);

            $product = $collection->getFirstItem();
            $urlKey = $product->getId() ? (string)$product->getUrlKey() : '';

            // url_key + the configured suffix is what Magento's own rewrite
            // generates for a product outside a category path.
            return $urlKey === '' ? null : $this->frontendUrl($urlKey . $this->urlSuffix('product'));
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not resolve a product URL', ['exception' => $error]);
            return null;
        }
    }

    private function categoryUrl(): ?string
    {
        try {
            $collection = $this->categories->create();
            $collection->addAttributeToSelect(['url_key', 'url_path'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('level', 2)
                ->setPageSize(1);

            $category = $collection->getFirstItem();
            $path = $category->getId() ? (string)($category->getUrlPath() ?: $category->getUrlKey()) : '';

            return $path === '' ? null : $this->frontendUrl($path . $this->urlSuffix('category'));
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not resolve a category URL', ['exception' => $error]);
            return null;
        }
    }

    /** Suffix a store appends to catalog URLs; empty is a legal setting. */
    private function urlSuffix(string $entity): string
    {
        return (string)$this->scopeConfig->getValue(
            'catalog/seo/' . $entity . '_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $this->stores->getDefaultStoreView()?->getId()
        );
    }

    /** @return string[] */
    private function locales(): array
    {
        $locales = [];
        foreach ($this->stores->getStores() as $store) {
            $locale = (string)$this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $store->getId()
            );
            if ($locale !== '') {
                $locales[$locale] = true;
            }
        }

        return array_slice(array_keys($locales), 0, 10);
    }

    /**
     * Active payment methods by title, which is what a buyer sees at checkout
     * and therefore what a synthetic journey can assert on.
     *
     * @return string[]
     */
    private function paymentMethods(): array
    {
        try {
            $titles = [];
            foreach ($this->payments->getStoreMethods() as $method) {
                $title = trim((string)$method->getTitle());
                if ($title !== '') {
                    $titles[$title] = true;
                }
            }

            return array_slice(array_keys($titles), 0, 20);
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not list payment methods', ['exception' => $error]);
            return [];
        }
    }

    /**
     * Currencies with the URL that switches to them. A currency without a
     * switch URL is declared but not monitorable, so the backend needs both.
     *
     * @return array<int, array{code: string, switchUrl?: string}>
     */
    private function currencies(): array
    {
        try {
            $store = $this->stores->getDefaultStoreView();
            if ($store === null) {
                return [];
            }

            $currencies = [];
            foreach (array_slice($store->getAvailableCurrencyCodes(true), 0, 10) as $code) {
                $code = (string)$code;
                if ($code === '') {
                    continue;
                }

                $switchUrl = $this->frontendUrl('directory/currency/switch');
                $currencies[] = array_filter([
                    'code' => $code,
                    'switchUrl' => $switchUrl === null ? null : $switchUrl . '?currency=' . rawurlencode($code),
                ], static fn($value): bool => $value !== null);
            }

            return $currencies;
        } catch (\Throwable $error) {
            $this->logger->debug('PingView could not list currencies', ['exception' => $error]);
            return [];
        }
    }
}
