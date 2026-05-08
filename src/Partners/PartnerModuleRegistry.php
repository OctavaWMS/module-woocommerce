<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Partners;

final class PartnerModuleRegistry
{
    public const ID_OCTAVA = 'octava';

    public const ID_IZPRATIBG = 'izpratibg';

    /** Brand pack id for gettext catalog {@see I18n\BrandedStrings}. */
    public const BRAND_PACK_IZPRATI = 'izprati';

    /** @var array<string, PartnerModule>|null */
    private static ?array $modules = null;

    public static function octava(): PartnerModule
    {
        return self::all()[self::ID_OCTAVA];
    }

    public static function izpratibg(): PartnerModule
    {
        return self::all()[self::ID_IZPRATIBG];
    }

    public static function byId(string $id): ?PartnerModule
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @return array<string, PartnerModule>
     */
    public static function all(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        $octava = new PartnerModule(
            self::ID_OCTAVA,
            'OctavaWMS',
            [
                'website' => 'https://www.octavawms.com',
                'contactUrl' => 'https://www.octavawms.com',
                'supportEmail' => '',
                'docsUrl' => '',
            ],
            'https://app.octavawms.com',
            null,
            static fn (string $_hint): bool => false,
        );

        $izprati = new PartnerModule(
            self::ID_IZPRATIBG,
            'Изпрати.БГ (Izprati.bg)',
            [
                'website' => 'https://izprati.bg',
                'contactUrl' => 'https://izprati.bg/contact',
                'supportEmail' => 'hello@izprati.bg',
                'docsUrl' => 'https://izprati.bg/docs-category/shopify-speedy-econt/',
            ],
            'https://app.izprati.bg',
            self::BRAND_PACK_IZPRATI,
            static function (string $hint): bool {
                $raw = strtolower(trim($hint));
                if ($raw === '') {
                    return false;
                }
                if ($raw === 'izpratibg') {
                    return true;
                }
                if (str_contains($raw, '.')) {
                    $raw = (string) (preg_replace('/^www\./', '', $raw) ?? $raw);
                }

                return str_contains($raw, 'izprati');
            },
        );

        self::$modules = [
            self::ID_OCTAVA => $octava,
            self::ID_IZPRATIBG => $izprati,
        ];

        return self::$modules;
    }
}
