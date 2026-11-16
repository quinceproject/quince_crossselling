<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\CrossSelling\Lists;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;

/**
 * Części zamienne i elementy zestawu: listy SKU wpisane przy produkcie.
 *
 * Lista leży w polu dodatkowym produktu (extra property rdzenia), po jednym na
 * sklep, jako numery artykułów po przecinku w kolejności z katalogu. Tak wchodzi
 * z importu i z API produktu bez rozbierania niczego. Przy elemencie zestawu
 * wolno dopisać ilość („ABC-1:2"); karuzela jej nie pokazuje, odczyt ją pomija.
 *
 * To NIE są pakiety PrestaShop (`ps_pack`) ani akcesoria (`ps_accessory`). Zestaw
 * zostaje zwykłym produktem z własnym stanem i ceną, a lista jest tylko informacją
 * dla klienta. Nic tutaj nie zmienia stanu, ceny ani dostępności.
 *
 * Numer, którego sklep nie zna, zostaje w polu: pokaże się sam, gdy produkt o tym
 * numerze trafi do sklepu.
 */
final class ProductLists
{
    /** Części zamienne produktu głównego (np. ściągacza). */
    public const SPARE_PARTS = 'spare_parts';

    /** Elementy zestawu. */
    public const SET_ITEMS = 'set_items';

    public const FIELDS = [self::SPARE_PARTS, self::SET_ITEMS];

    /** Najdłuższe pole: zapas na około 250 numerów. */
    public const FIELD_SIZE = 4000;

    private const MODULE = 'quince_crossselling';

    /** Kolumna pola w `product_extra_shop`: umowa rdzenia, moduł i nazwa pola. */
    public static function column(string $pole): string
    {
        return self::MODULE . '_' . $pole;
    }

    /**
     * Numery z pola w kolejności wpisania, bez powtórzeń i bez ilości.
     *
     * @return string[]
     */
    public static function skus(string $wartosc): array
    {
        $wynik = [];

        foreach (preg_split('/[,\r\n]+/', $wartosc) ?: [] as $kawalek) {
            $sku = trim((string) preg_replace('/:\s*\d+\s*$/', '', trim($kawalek)));

            if ($sku !== '' && mb_strlen($sku) <= 64) {
                $wynik[$sku] = true;
            }
        }

        return array_keys($wynik);
    }

    /**
     * Produkty z listy wpisanej przy produkcie, w kolejności listy.
     *
     * @param int[] $idProducts produkty, których listy czytamy (koszyk ma ich kilka)
     *
     * @return int[]
     */
    public function listed(array $idProducts, string $pole, int $idShop): array
    {
        $idProducts = array_values(array_filter(array_map('intval', $idProducts)));

        if ($idProducts === [] || !in_array($pole, self::FIELDS, true)) {
            return [];
        }

        $wiersze = $this->zapytanie(sprintf(
            'SELECT id_product, `%s` AS lista FROM `%sproduct_extra_shop` WHERE id_shop = %d AND id_product IN (%s)',
            self::column($pole),
            _DB_PREFIX_,
            $idShop,
            implode(',', $idProducts)
        ));

        // Kolejność produktów źródłowych zostaje: najpierw lista pierwszego.
        $listy = [];

        foreach ($wiersze as $wiersz) {
            $listy[(int) $wiersz['id_product']] = self::skus((string) $wiersz['lista']);
        }

        $numery = [];

        foreach ($idProducts as $id) {
            foreach ($listy[$id] ?? [] as $sku) {
                $numery[$sku] = true;
            }
        }

        if ($numery === []) {
            return [];
        }

        $poNumerze = [];

        foreach ($this->zapytanie(sprintf(
            'SELECT id_product, reference FROM `%sproduct` WHERE reference IN (%s) ORDER BY id_product',
            _DB_PREFIX_,
            implode(',', array_map(static fn (string $s): string => '"' . pSQL($s) . '"', array_keys($numery)))
        )) as $wiersz) {
            $poNumerze[(string) $wiersz['reference']][] = (int) $wiersz['id_product'];
        }

        $wynik = [];

        foreach (array_keys($numery) as $sku) {
            foreach ($poNumerze[$sku] ?? [] as $id) {
                if (!in_array($id, $idProducts, true)) {
                    $wynik[$id] = true;
                }
            }
        }

        return array_keys($wynik);
    }

    /**
     * Widok odwrotny: produkty, które mają ten produkt na swojej liście
     * („Pasuje do", „W zestawach"). Liczony z tych samych pól, katalog nie wysyła
     * niczego drugi raz.
     *
     * @param int[] $idProducts
     *
     * @return int[]
     */
    public function listing(array $idProducts, string $pole, int $idShop): array
    {
        $idProducts = array_values(array_filter(array_map('intval', $idProducts)));

        if ($idProducts === [] || !in_array($pole, self::FIELDS, true)) {
            return [];
        }

        $numery = [];

        foreach ($this->zapytanie(sprintf(
            'SELECT reference FROM `%sproduct` WHERE id_product IN (%s) AND reference <> ""',
            _DB_PREFIX_,
            implode(',', $idProducts)
        )) as $wiersz) {
            $numery[(string) $wiersz['reference']] = true;
        }

        if ($numery === []) {
            return [];
        }

        // LIKE zawęża do wierszy, w których numer w ogóle występuje; dokładne
        // dopasowanie robi odczyt listy, bo „AB-1" siedzi też w „AB-10".
        $warunki = array_map(
            static fn (string $sku): string => sprintf(
                '`%s` LIKE "%%%s%%"',
                self::column($pole),
                addcslashes(pSQL($sku), '%_')
            ),
            array_keys($numery)
        );

        $wynik = [];

        foreach ($this->zapytanie(sprintf(
            'SELECT id_product, `%s` AS lista FROM `%sproduct_extra_shop` WHERE id_shop = %d AND (%s) ORDER BY id_product',
            self::column($pole),
            _DB_PREFIX_,
            $idShop,
            implode(' OR ', $warunki)
        )) as $wiersz) {
            $id = (int) $wiersz['id_product'];

            if (in_array($id, $idProducts, true)) {
                continue;
            }

            if (array_intersect_key(array_flip(self::skus((string) $wiersz['lista'])), $numery) !== []) {
                $wynik[$id] = true;
            }
        }

        return array_keys($wynik);
    }

    /**
     * Zapytanie, które nie wywraca strony: pole zakłada dopiero instalacja
     * modułu, a hook potrafi odpalić wcześniej.
     *
     * @return array<int,array<string,mixed>>
     */
    private function zapytanie(string $sql): array
    {
        $wynik = Db::getInstance()->executeS($sql, true, false);

        return is_array($wynik) ? $wynik : [];
    }
}
