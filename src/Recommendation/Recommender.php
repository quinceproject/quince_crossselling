<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\CrossSelling\Recommendation;

use Configuration;
use Context;
use Db;
use Product;
use Quince\CrossSelling\Settings\CrossSellingSettings;
use StockAvailable;

/**
 * Dobór produktów do podpowiedzi.
 *
 * Źródła idą po kolei, od najtrafniejszego do najogólniejszego, aż zbierze się
 * tyle produktów, ile miejsce potrzebuje:
 *
 * 1. historia zamówień: co klienci kupowali razem z produktami wyjściowymi,
 * 2. akcesoria przypisane w katalogu,
 * 3. produkty z kategorii domyślnej,
 * 4. bestsellery sklepu.
 *
 * Z listy wypadają produkty wyjściowe, to, co klient ma już w koszyku,
 * powtórzenia oraz (gdy sklep tak ustawił) wszystko, czego nie da się od razu
 * dodać do koszyka.
 *
 * Tryb „do darmowej dostawy" zbiera szerszą pulę i układa ją cenowo: najpierw
 * najtańsze produkty, które same domykają brakującą kwotę, potem te najbliższe
 * niej od dołu.
 */
final class Recommender
{
    /** Ile razy szerszą pulę zbieramy, gdy trzeba z niej wybierać cenowo. */
    private const ZAPAS = 4;

    /**
     * @param int[]    $zrodlowe  produkty, do których szukamy podpowiedzi
     * @param int[]    $pomin     produkty, których nie podpowiadamy (koszyk)
     * @param string[] $zrodla    włączone źródła w kolejności z ustawień
     * @param float|null $brakDoProgu kwota brakująca do darmowej dostawy, gdy tryb działa
     *
     * @return int[] identyfikatory produktów w kolejności wyświetlania
     */
    public function recommend(
        array $zrodlowe,
        array $pomin,
        array $zrodla,
        int $ile,
        bool $tylkoDoKupienia,
        ?float $brakDoProgu = null
    ): array {
        $zrodlowe = array_values(array_unique(array_filter(array_map('intval', $zrodlowe))));

        if ($ile <= 0) {
            return [];
        }

        $idShop = (int) Context::getContext()->shop->id;
        $pominiete = array_flip(array_merge($zrodlowe, array_map('intval', $pomin)));
        $cel = $brakDoProgu !== null ? $ile * self::ZAPAS : $ile;
        $pula = [];

        foreach ($zrodla as $zrodlo) {
            foreach ($this->zeZrodla($zrodlo, $zrodlowe, $cel * 2, $idShop) as $idProduct) {
                if (isset($pominiete[$idProduct]) || isset($pula[$idProduct])) {
                    continue;
                }

                if (!$this->doPokazania($idProduct, $idShop, $tylkoDoKupienia)) {
                    continue;
                }

                $pula[$idProduct] = true;

                if (count($pula) >= $cel) {
                    break 2;
                }
            }
        }

        $wynik = array_keys($pula);

        if ($brakDoProgu !== null && $brakDoProgu > 0) {
            $wynik = $this->podProg($wynik, $brakDoProgu);
        }

        return array_slice($wynik, 0, $ile);
    }

    /** @return int[] */
    private function zeZrodla(string $zrodlo, array $zrodlowe, int $limit, int $idShop): array
    {
        switch ($zrodlo) {
            case CrossSellingSettings::SOURCE_BOUGHT:
                return $this->kupowaneRazem($zrodlowe, $limit, $idShop);

            case CrossSellingSettings::SOURCE_ACCESSORIES:
                return $this->akcesoria($zrodlowe, $limit);

            case CrossSellingSettings::SOURCE_CATEGORY:
                return $this->zKategorii($zrodlowe, $limit, $idShop);

            case CrossSellingSettings::SOURCE_BESTSELLERS:
                return $this->bestsellery($limit);

            default:
                return [];
        }
    }

    /**
     * Produkty z tych samych zamówień, co produkty wyjściowe, od najczęściej
     * kupowanych razem. Liczą się tylko zamówienia opłacone (`valid`), bo
     * porzucone i anulowane nie mówią nic o tym, co ludzie naprawdę biorą razem.
     * Pary są policzone zawczasu, patrz `BoughtTogether`.
     *
     * @return int[]
     */
    private function kupowaneRazem(array $zrodlowe, int $limit, int $idShop): array
    {
        return (new BoughtTogether())->forProducts($zrodlowe, $limit, $idShop);
    }

    /** @return int[] */
    private function akcesoria(array $zrodlowe, int $limit): array
    {
        if ($zrodlowe === []) {
            return [];
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT DISTINCT id_product_2 AS id
             FROM `' . _DB_PREFIX_ . 'accessory`
             WHERE id_product_1 IN (' . implode(',', $zrodlowe) . ')
             LIMIT ' . $limit
        );

        return $this->identyfikatory($wiersze);
    }

    /**
     * Produkty z kategorii domyślnych produktów wyjściowych, w kolejności
     * ustawionej w katalogu.
     *
     * @return int[]
     */
    private function zKategorii(array $zrodlowe, int $limit, int $idShop): array
    {
        if ($zrodlowe === []) {
            return [];
        }

        $kategorie = Db::getInstance()->executeS(
            'SELECT DISTINCT id_category_default AS id
             FROM `' . _DB_PREFIX_ . 'product_shop`
             WHERE id_shop = ' . $idShop . ' AND id_product IN (' . implode(',', $zrodlowe) . ')'
        );

        $kategorie = $this->identyfikatory($kategorie);

        if ($kategorie === []) {
            return [];
        }

        $wiersze = Db::getInstance()->executeS(
            'SELECT cp.id_product AS id
             FROM `' . _DB_PREFIX_ . 'category_product` cp
             WHERE cp.id_category IN (' . implode(',', $kategorie) . ')
             ORDER BY cp.position ASC
             LIMIT ' . $limit
        );

        return $this->identyfikatory($wiersze);
    }

    /** @return int[] */
    private function bestsellery(int $limit): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_product AS id
             FROM `' . _DB_PREFIX_ . 'product_sale`
             ORDER BY quantity DESC
             LIMIT ' . $limit
        );

        return $this->identyfikatory($wiersze);
    }

    /**
     * Czy produkt w ogóle może stanąć w podpowiedziach.
     *
     * Zawsze: aktywny i widoczny w katalogu. Przy „tylko do kupienia" dodatkowo:
     * wystawiony do zamówienia, z ceną pokazywaną klientowi i dostępny, czyli
     * na stanie albo z pozwoleniem na zamawianie bez stanu. Podpowiedź, której
     * nie da się dodać do koszyka, zabiera miejsce tej, którą dałoby się kupić.
     */
    private function doPokazania(int $idProduct, int $idShop, bool $tylkoDoKupienia): bool
    {
        $produkt = Db::getInstance()->getRow(
            'SELECT active, visibility, available_for_order, show_price
             FROM `' . _DB_PREFIX_ . 'product_shop`
             WHERE id_product = ' . $idProduct . ' AND id_shop = ' . $idShop
        );

        if (!$produkt || (int) $produkt['active'] !== 1 || !in_array($produkt['visibility'], ['both', 'catalog'], true)) {
            return false;
        }

        if (!$tylkoDoKupienia) {
            return true;
        }

        if ((int) $produkt['available_for_order'] !== 1 || (int) $produkt['show_price'] !== 1 || Configuration::isCatalogMode()) {
            return false;
        }

        if ((int) StockAvailable::getQuantityAvailableByProduct($idProduct, null, $idShop) > 0) {
            return true;
        }

        return (bool) Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($idProduct, $idShop));
    }

    /**
     * Ułożenie pod brakującą kwotę: najpierw najtańsze produkty, które same
     * domykają próg, potem produkty tańsze od brakującej kwoty, od najdroższego.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    private function podProg(array $ids, float $brak): array
    {
        $domykaja = [];
        $ponizej = [];

        foreach ($ids as $id) {
            $cena = (float) Product::getPriceStatic($id, true);

            if ($cena >= $brak) {
                $domykaja[$id] = $cena;
            } else {
                $ponizej[$id] = $cena;
            }
        }

        asort($domykaja);
        arsort($ponizej);

        return array_merge(array_keys($domykaja), array_keys($ponizej));
    }

    /** @return int[] */
    private function identyfikatory($wiersze): array
    {
        if (!is_array($wiersze)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $wiersz): int => (int) $wiersz['id'],
            $wiersze
        )));
    }
}
