<?php

declare(strict_types=1);

/**
 * Изпрати.БГ — Bulgarian UI overrides (keys = canonical English msgids from __('…', 'octavawms')).
 *
 * Add rows here as you introduce new tenant-facing strings. Default Octava installs
 * skip this file ({@see BrandedStrings}).
 */

return [
    // Integration identity
    'OctavaWMS Connector' => 'Изпрати.БГ: Създай товарителница',
    'Shipment' => 'Пратка',
    'Shipping labels and parcel boxes' => 'Товарителница и кутии за пратки',

    // Settings page — connection area
    'Connected to OctavaWMS' => 'Свързано с Изпрати.БГ',
    'Connect to OctavaWMS' => 'Свързване с Изпрати.БГ',
    'Connect your store to OctavaWMS for shipping label generation and order management.' =>
        'Свържете магазина с Изпрати.БГ за генериране на товарителници и управление на поръчки.',
    'Send new orders to OctavaWMS automatically' => 'Изпращай нови поръчки към Изпрати.БГ автоматично',

    // Settings page — carrier matrix
    'Carrier meta mapping (Woo → Octava)' => 'Carrier meta mapping (Woo → Изпрати.БГ)',
    'Map WooCommerce order meta (e.g. courierName, courierID) and optional delivery_type to a carrier service, rate, and pickup strategy. Saved to your OctavaWMS integration source (same as Orderadmin settings).' =>
        'Свържете мета данни на поръчката (напр. courierName, courierID) и delivery_type с услуга, тарифа и стратегия за получаване. Записва се в интеграционния Ви акаунт в Изпрати.БГ.',

    // Connection / auth error messages
    'Connect request failed. Check your site can reach the OctavaWMS service.' =>
        'Заявката за свързване не успя. Проверете дали сайтът достига услугата Изпрати.БГ.',

    // Order panel / label meta-box
    'Could not load OctavaWMS status.' => 'Неуспешно зареждане на статус от Изпрати.БГ.',
    'Could not open Octava panel.' => 'Панелът на Изпрати.БГ не може да се отвори.',
    'Could not open Octava panel. Try connecting again or check logs.' =>
        'Панелът на Изпрати.БГ не може да се отвори. Опитайте отново да се свържете или проверете логовете.',
    'OctavaWMS Connector: no API key stored yet. One will be requested automatically on the first order action, or you can connect manually on the Integrations tab.' =>
        'Изпрати.БГ: няма записан API ключ. Ще се поиска автоматично при първо действие с поръчка или можете да се свържете ръчно от раздел Интеграции.',
    'OctavaWMS could not generate a shipping label. See order notes.' =>
        'Изпрати.БГ не успя да генерира товарителница. Вижте бележките към поръчката.',
    'OctavaWMS could not process this shipment. See the message below or open the delivery request in OctavaWMS.' =>
        'Изпрати.БГ не успя да обработи тази пратка. Вижте съобщението по-долу или отворете заявката за доставка в Изпрати.БГ.',
    'OctavaWMS label generation failed: %s' => 'Неуспешно генериране на товарителница в Изпрати.БГ: %s',
    'Order is in OctavaWMS; waiting for a shipment (delivery request) to appear.' =>
        'Поръчката е в Изпрати.БГ; очаква се пратка (заявка за доставка).',
    'This order is not in OctavaWMS yet. Upload it to create shipments and labels.' =>
        'Поръчката все още не е в Изпрати.БГ. Качете я за създаване на пратки и товарителници.',
];
