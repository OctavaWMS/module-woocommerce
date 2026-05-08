<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Partners;

/**
 * White-label / tenant metadata (Shopify {@see APP_CONFIGS} analogue).
 *
 * @phpstan-type SupportShape array{website?: string, contactUrl?: string, supportEmail?: string, docsUrl?: string}
 */
final class PartnerModule
{
    /** @var callable(string): bool */
    private $hintMatcher;

    /**
     * @param SupportShape $support
     * @param callable(string): bool $hintMatcher
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $support,
        public readonly string $panelAppBase,
        public readonly ?string $brandPack,
        callable $hintMatcher,
    ) {
        $this->hintMatcher = $hintMatcher;
    }

    public function matchesHint(string $hint): bool
    {
        return ($this->hintMatcher)($hint);
    }
}
