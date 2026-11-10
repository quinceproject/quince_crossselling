<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\CrossSelling\Controller\Admin;

use Configuration;
use Quince\CrossSelling\Recommendation\BoughtTogether;
use Quince\CrossSelling\Settings\CrossSellingSettings;
use Quince\Engine\Controller\Admin\QuinceController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ustawienia podpowiedzi produktów do dokupienia.
 *
 * Dziedziczy po klasie z silnika, więc strona dostaje ten sam sidebar, pasek
 * zapisu i wygląd co reszta panelu Quince.
 */
class SettingsController extends QuinceController
{
    private const DOMENA = 'Modules.Quincecrossselling.Admin';

    public function __construct(private readonly CrossSellingSettings $settings)
    {
    }

    public function indexAction(): Response
    {
        return $this->renderPanel(
            '@Modules/quince_crossselling/views/templates/admin/settings.html.twig',
            [
                'settings' => $this->settings->all(),
                'translations' => $this->settings->translations(),
                'sources' => $this->settings->sourcesByPlacement(),
                'sourceList' => CrossSellingSettings::SOURCES,
                'languages' => $this->languages(),
                'limits' => [
                    'countMin' => CrossSellingSettings::COUNT_MIN,
                    'countMax' => CrossSellingSettings::COUNT_MAX,
                ],
                // Próg darmowej dostawy pokazujemy przy przełączniku, żeby sklep
                // wiedział, pod jaką kwotę dobór będzie układał podpowiedzi,
                // i żeby było widać, kiedy progu w ogóle nie ma.
                'freeShippingThreshold' => (float) Configuration::get('PS_SHIPPING_FREE_PRICE'),
                'currencySign' => (string) $this->getContext()->currency->sign,
                'pairs' => (new BoughtTogether())->summary(),
                'layoutTitle' => 'Cross-selling',
                'layoutTitleDomain' => self::DOMENA,
                'quinceRoute' => 'quince_crossselling_settings',
                'quinceNavRoute' => 'quince_crossselling_settings',
            ]
        );
    }

    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_crossselling_settings_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_crossselling_settings');
        }

        $this->settings->save($request->request->all());
        $this->addFlash('success', $this->trans('Settings saved.', self::DOMENA));

        return $this->redirectToRoute('quince_crossselling_settings');
    }

    /** Przeliczenie „kupowanych razem" od nowa z historii zamówień. */
    public function rebuildAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_crossselling_rebuild', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_crossselling_settings');
        }

        (new BoughtTogether())->rebuild();
        $this->addFlash('success', $this->trans('Bought together recalculated from the order history.', self::DOMENA));

        return $this->redirectToRoute('quince_crossselling_settings');
    }
}
