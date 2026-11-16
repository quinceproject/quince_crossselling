<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\CrossSelling\Form;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShopBundle\Form\Admin\Type\ProductSearchType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pole listy produktów (części zamienne, elementy zestawu) w formularzu produktu.
 *
 * Wygląda i działa jak „Produkty powiązane" rdzenia: wyszukiwarka produktów
 * z miniaturami. Pole dodatkowe rdzenia podaje i odbiera tekst, a wyszukiwarka
 * jest kolekcją, której nie da się podać tekstu wprost (Symfony oczekuje tablicy
 * już przed zamianą danych). Dlatego pole jest złożone: wyszukiwarka siedzi
 * w środku, a zamianę tekstu na produkty robi konwerter na poziomie tego pola.
 *
 * Skrypt `views/js/admin/product-lists.js` uruchamia wyszukiwarkę, tak jak rdzeń
 * robi to dla „Produktów powiązanych".
 */
final class ProductListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('products', ProductSearchType::class, [
            'include_combinations' => false,
            'label' => false,
            'min_length' => 3,
            'limit' => 0,
            'entry_options' => ['block_prefix' => 'related_product'],
            'attr' => ['data-quince-product-list' => '1'],
            'remote_url' => self::adresWyszukiwania(),
        ]);

        $builder->addModelTransformer(new SkuListTransformer());
    }

    /**
     * Numery z listy, których sklep jeszcze nie ma, dopisane do opisu pola.
     * Zostają na liście i pokażą się same, gdy produkt trafi do sklepu.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $nieznane = [];

        foreach ($form->getConfig()->getModelTransformers() as $konwerter) {
            if ($konwerter instanceof SkuListTransformer) {
                $nieznane = $konwerter->unknown();
            }
        }

        if ($nieznane === []) {
            return;
        }

        $dopisek = Context::getContext()->getTranslator()->trans(
            'Item numbers the shop does not have yet: %list%. They stay on the list and show up once those products are added.',
            ['%list%' => implode(', ', $nieznane)],
            'Modules.Quincecrossselling.Admin'
        );

        $view->vars['help'] = trim(((string) ($view->vars['help'] ?? '')) . ' ' . $dopisek);
    }

    /**
     * Ten sam adres wyszukiwania co przy „Produktach powiązanych".
     *
     * Rdzeń bierze do niego język pracownika. Przy rejestracji pola rdzeń buduje
     * je na próbę, także w konsoli, gdzie pracownika nie ma, i wtedy adres się
     * nie składa, a moduł zostaje wyłączony. Bez pracownika bierzemy język sklepu.
     */
    private static function adresWyszukiwania(): string
    {
        $kontekst = Context::getContext();
        $iso = $kontekst->employee !== null && (int) $kontekst->employee->id_lang > 0
            ? (string) \Language::getIsoById((int) $kontekst->employee->id_lang)
            : '';

        if ($iso === '') {
            $iso = (string) \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT'));
        }

        return SymfonyContainer::getInstance()->get('router')->generate('admin_products_search_products_for_association', [
            'languageCode' => $iso !== '' ? $iso : 'en',
            'query' => '__QUERY__',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => true,
            'required' => false,
            'invalid_message' => 'Every product on this list needs a reference (SKU).',
        ]);
    }
}
