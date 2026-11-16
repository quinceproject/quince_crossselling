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
use Db;
use Image;
use Product;
use Quince\CrossSelling\Lists\ProductLists;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Lista SKU w polu produktu ↔ produkty w wyszukiwarce panelu.
 *
 * W bazie i w API lista zostaje tekstem z numerami artykułów, bo tak wysyła ją
 * katalog. Panel pokazuje ją wyszukiwarką produktów rdzenia (tą samą co przy
 * „Produktach powiązanych"), która operuje na produktach. Ta klasa tłumaczy jedno
 * na drugie.
 *
 * Numer, którego sklep jeszcze nie ma, nie może być pozycją wyszukiwarki. Odczyt
 * zapamiętuje takie numery, a zapis dokłada je z powrotem na koniec listy: zapis
 * w panelu nie kasuje tego, co przyszło z katalogu.
 */
final class SkuListTransformer implements DataTransformerInterface
{
    /** @var string[] numery z pola, których sklep nie ma */
    private array $nieznane = [];

    /** @return string[] */
    public function unknown(): array
    {
        return $this->nieznane;
    }

    /**
     * Tekst z bazy → pozycje wyszukiwarki w kolejności listy.
     *
     * @param mixed $value
     *
     * @return array{products:array<int,array{id:int,name:string,image:string}>}
     */
    public function transform($value): array
    {
        $numery = ProductLists::skus(is_scalar($value) ? (string) $value : '');
        $this->nieznane = [];

        if ($numery === []) {
            return ['products' => []];
        }

        $poNumerze = [];

        foreach (Db::getInstance()->executeS(sprintf(
            'SELECT id_product, reference FROM `%sproduct` WHERE reference IN (%s) ORDER BY id_product',
            _DB_PREFIX_,
            implode(',', array_map(static fn (string $s): string => '"' . pSQL($s) . '"', $numery))
        )) ?: [] as $wiersz) {
            // Dwa produkty z tym samym numerem: bierzemy pierwszy, jak karuzela.
            $poNumerze[(string) $wiersz['reference']] ??= (int) $wiersz['id_product'];
        }

        $pozycje = [];

        foreach ($numery as $sku) {
            if (!isset($poNumerze[$sku])) {
                $this->nieznane[] = $sku;

                continue;
            }

            $pozycje[] = $this->pozycja($poNumerze[$sku], $sku);
        }

        return ['products' => $pozycje];
    }

    /**
     * Pozycje wyszukiwarki → tekst do bazy: numery wybranych produktów w kolejności,
     * a za nimi numery, których sklep nie zna.
     *
     * @param mixed $value
     */
    public function reverseTransform($value): string
    {
        $ids = [];

        foreach (is_array($value['products'] ?? null) ? $value['products'] : [] as $pozycja) {
            $id = (int) (is_array($pozycja) ? ($pozycja['id'] ?? 0) : 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $numerProduktu = [];

        if ($ids !== []) {
            foreach (Db::getInstance()->executeS(sprintf(
                'SELECT id_product, reference FROM `%sproduct` WHERE id_product IN (%s)',
                _DB_PREFIX_,
                implode(',', array_unique($ids))
            )) ?: [] as $wiersz) {
                $numerProduktu[(int) $wiersz['id_product']] = trim((string) $wiersz['reference']);
            }
        }

        $numery = [];

        foreach ($ids as $id) {
            $sku = $numerProduktu[$id] ?? '';

            // Lista trzyma numery artykułów, więc produkt bez numeru nie ma jak na nią wejść.
            if ($sku === '') {
                throw new TransformationFailedException('Product without a reference.');
            }

            $numery[$sku] = true;
        }

        foreach ($this->nieznane as $sku) {
            $numery[$sku] = true;
        }

        return implode(', ', array_keys($numery));
    }

    /** @return array{id:int,name:string,image:string} */
    private function pozycja(int $idProduct, string $sku): array
    {
        $kontekst = Context::getContext();
        $idLang = (int) $kontekst->language->id;

        // Ta sama postać co „Produkty powiązane" w rdzeniu: nazwa z numerem w nawiasie.
        $nazwa = (string) Product::getProductName($idProduct, null, $idLang) . ' (ref: ' . $sku . ')';

        $okladka = Image::getCover($idProduct);
        $przyjazny = (string) Db::getInstance()->getValue(
            'SELECT link_rewrite FROM `' . _DB_PREFIX_ . 'product_lang` WHERE id_product = ' . $idProduct
            . ' AND id_lang = ' . $idLang . ' AND id_shop = ' . (int) $kontekst->shop->id
        );
        $obrazek = is_array($okladka) && !empty($okladka['id_image'])
            ? $kontekst->link->getImageLink($przyjazny !== '' ? $przyjazny : 'product', (string) $okladka['id_image'], 'small_default')
            : $kontekst->link->getImageLink('product', $kontekst->language->iso_code . '-default', 'small_default');

        return ['id' => $idProduct, 'name' => $nazwa, 'image' => $obrazek];
    }
}
