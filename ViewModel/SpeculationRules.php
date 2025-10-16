<?php

namespace MageOS\ThemeOptimization\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;

class SpeculationRules implements ArgumentInterface
{
    protected const CONFIG_PATH = 'system/speculation_rules/';
    protected const MODE_PREFETCH = 'prefetch';
    protected const MODE_PRERENDER = 'prerender';
    protected const FETCH_MODES = [self::MODE_PREFETCH, self::MODE_PRERENDER];
    protected const EAGERNESS_MODES = ['conservative', 'moderate', 'eager'];

    public function __construct(
        protected ScopeConfigInterface $scopeConfig,
        protected UrlInterface         $urlBuilder,
        protected SerializerInterface  $serializer,
        protected DesignInterface      $viewDesign
    )
    {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->getConfigValue('enable');
    }

    public function getMode(): string
    {
        $mode = $this->getConfigValue('mode');

        if (in_array($mode, self::FETCH_MODES, true)) {
            return $mode;
        }

        return self::MODE_PREFETCH;
    }

    public function getEagerness(): string
    {
        $eagerness = $this->getConfigValue('eagerness');

        if (in_array($eagerness, self::EAGERNESS_MODES, true)) {
            return $eagerness;
        }

        return 'moderate';
    }

    /**
     * Check if the current mode is prerender
     *
     * @return bool
     */
    public function isPrerenderMode(): bool
    {
        return $this->getMode() === self::MODE_PRERENDER;
    }

    /**
     * Get prerendering change script for customer data reinitialization
     * Returns script only when prerender mode is enabled
     * Uses different approaches for Hyva vs Luma themes
     *
     * @return string
     */
    public function getPrerenderingScript(): string
    {
        if (!$this->isPrerenderMode()) {
            return '';
        }

        // Hyva theme uses a custom event, Luma uses RequireJS
        $reloadAction = $this->isHyva()
            ? "window.dispatchEvent(new CustomEvent('reload-customer-section-data'));"
            : "require(['Magento_Customer/js/customer-data'], customerData => {
                    customerData.init();
               });";

        return <<<JS
        (() => {
            if (document.prerendering) {
                document.addEventListener("prerenderingchange", () => {
                    $reloadAction
                }, { once: true });
            }
        })();
        JS;
    }

    public function getSpeculationRules(): array
    {
        // Possible future development: add support for multiple modes and rulesets at once.
        return [
            $this->getMode() => [
                [
                    'source' => 'document',
                    'where' => $this->buildRules(),
                    'eagerness' => $this->getEagerness(),
                ],
            ],
        ];
    }

    public function getSpeculationRulesJson(): string
    {
        $rules = $this->getSpeculationRules();

        return $this->serializer->serialize($rules);
    }

    protected function buildRules(): array
    {
        // Include all URLs by default
        $rules = [
            'and' => [
                ['href_matches' => '/*']
            ],
        ];

        // Exclude path patterns (wildcards)
        $rules['and'][] = $this->getExcludedPaths();

        // Exclude file extensions
        array_push($rules['and'], ...$this->getExcludedExtensions());

        // Exclude selectors
        array_push($rules['and'], ...$this->getExcludedSelectors());

        // TODO: Add extensibility?

        // Always exclude common unsafe targets
        $rules['and'][] = ['not' => ['selector_matches' => '[rel=nofollow]']];
        $rules['and'][] = ['not' => ['selector_matches' => '[target=_blank]']];
        $rules['and'][] = ['not' => ['selector_matches' => '[target=_parent]']];
        $rules['and'][] = ['not' => ['selector_matches' => '[target=_top]']];

        return $rules;
    }

    protected function getConfigValue(string $key): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH . $key,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getExcludedPaths(): array
    {
        $paths = explode("\n", (string)$this->getConfigValue('exclude_paths'));

        foreach ($paths as &$pattern) {
            $pattern = trim(trim($pattern), '/');
        }
        $paths = array_filter($paths);

        if (empty($paths)) {
            return [];
        }

        return ['not' => ['href_matches' => '/*(' . implode('|', $paths) . ')/*']];
    }

    public function getExcludedExtensions(): array
    {
        $rules = [];

        $extensions = explode(',', (string)$this->getConfigValue('exclude_extensions'));
        $extensions = array_filter(array_map('trim', $extensions));
        foreach ($extensions as $extension) {
            $rules[] = ['not' => ['href_matches' => sprintf('*.%s', ltrim($extension, '.'))]];
        }

        return $rules;
    }

    public function getExcludedSelectors(): array
    {
        $rules = [];

        $selectors = explode("\n", (string)$this->getConfigValue('exclude_selectors'));
        $selectors = array_filter(array_map('trim', $selectors));
        foreach ($selectors as $selector) {
            $rules[] = ['not' => ['selector_matches' => $selector]];
        }

        return $rules;
    }

    /**
     * Check if current theme is Hyva or extends from Hyva
     *
     * @return bool
     */
    private function isHyva(): bool
    {
        $theme = $this->viewDesign->getDesignTheme();
        while ($theme) {
            if (strpos($theme->getCode(), 'Hyva/') === 0) {
                return true;
            }
            $theme = $theme->getParentTheme();
        }
        return false;
    }
}
