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
use Db;
use Order;
use Validate;

/**
 * „Kupowane razem": pary produktów z opłaconych zamówień, policzone zawczasu.
 *
 * Liczenie par na bieżąco z historii zamówień to złączenie tabeli pozycji
 * zamówień samej ze sobą. Na sklepie z tysiącami zamówień kosztuje to przy
 * każdym wyświetleniu karty produktu i szuflady koszyka. Dlatego pary leżą we
 * własnej tabeli: dla każdej pary (produkt, produkt kupiony z nim) liczba
 * opłaconych zamówień, w których stały obok siebie, i data ostatniego z nich.
 *
 * Tabela nadąża za sklepem sama. Zamówienie, które staje się opłacone (status
 * „ważny"), dokłada swoje pary; zamówienie, które przestaje nim być (anulowanie,
 * zwrot płatności), je odejmuje. Zmian, przy których sklep nie ogłasza zmiany
 * statusu (zamówienia wgrane bezpośrednio do bazy przy przenosinach sklepu,
 * pozycje dopisane w panelu), nie widać, więc całość da się przeliczyć od nowa
 * z historii: przy instalacji i przyciskiem w panelu.
 */
final class BoughtTogether
{
    public const TABLE = 'quince_crossselling_pair';

    /** Kiedy tabelę ostatnio przeliczono od nowa. */
    private const BUILT_AT = 'QUINCE_CROSS_PAIRS_BUILT_AT';

    /** Ile zamówień obejmuje jedno zapytanie przy przeliczaniu. */
    private const PACZKA = 5000;

    public static function installTable(): bool
    {
        return (bool) Db::getInstance()->execute(self::definicja(_DB_PREFIX_ . self::TABLE));
    }

    public static function dropTable(): bool
    {
        Configuration::deleteByName(self::BUILT_AT);

        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * Produkty kupowane razem z podanymi, od najczęściej kupowanych.
     *
     * @param int[] $zrodlowe
     *
     * @return int[]
     */
    public function forProducts(array $zrodlowe, int $limit, int $idShop): array
    {
        if ($zrodlowe === [] || $limit <= 0) {
            return [];
        }

        $lista = implode(',', array_map('intval', $zrodlowe));

        $wiersze = Db::getInstance()->executeS(
            'SELECT id_product_related AS id, SUM(orders) AS razy, MAX(last_order) AS ostatnio
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_shop = ' . $idShop . '
               AND id_product IN (' . $lista . ')
               AND id_product_related NOT IN (' . $lista . ')
               AND orders > 0
             GROUP BY id_product_related
             ORDER BY razy DESC, ostatnio DESC
             LIMIT ' . $limit
        );

        if (!is_array($wiersze)) {
            return [];
        }

        return array_map(static fn (array $w): int => (int) $w['id'], $wiersze);
    }

    /**
     * Dopisanie (+1) albo odjęcie (−1) par jednego zamówienia.
     */
    public function applyOrder(int $idOrder, int $zmiana): void
    {
        $zamowienie = new Order($idOrder);

        if (!Validate::isLoadedObject($zamowienie) || ($zmiana !== 1 && $zmiana !== -1)) {
            return;
        }

        $produkty = Db::getInstance()->executeS(
            'SELECT DISTINCT product_id FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . $idOrder
        );
        $produkty = is_array($produkty) ? array_map(static fn (array $w): int => (int) $w['product_id'], $produkty) : [];

        if (count($produkty) < 2) {
            return;
        }

        $idShop = (int) $zamowienie->id_shop;
        $tabela = '`' . _DB_PREFIX_ . self::TABLE . '`';
        $db = Db::getInstance();

        if ($zmiana === -1) {
            $lista = implode(',', $produkty);
            $db->execute(
                'UPDATE ' . $tabela . ' SET orders = orders - 1
                 WHERE id_shop = ' . $idShop . '
                   AND id_product IN (' . $lista . ') AND id_product_related IN (' . $lista . ')
                   AND id_product <> id_product_related AND orders > 0'
            );

            return;
        }

        $data = pSQL((string) $zamowienie->date_add);
        $wiersze = [];

        foreach ($produkty as $a) {
            foreach ($produkty as $b) {
                if ($a !== $b) {
                    $wiersze[] = '(' . $idShop . ',' . $a . ',' . $b . ",1,'" . $data . "')";
                }
            }
        }

        // Duże zamówienie daje kwadrat par, więc zapis idzie porcjami.
        foreach (array_chunk($wiersze, 500) as $porcja) {
            $db->execute(
                'INSERT INTO ' . $tabela . ' (id_shop, id_product, id_product_related, orders, last_order)
                 VALUES ' . implode(',', $porcja) . '
                 ON DUPLICATE KEY UPDATE
                     orders = orders + 1,
                     last_order = GREATEST(COALESCE(last_order, VALUES(last_order)), VALUES(last_order))'
            );
        }
    }

    /**
     * Przeliczenie całej tabeli z historii opłaconych zamówień.
     *
     * Liczymy do tabeli roboczej i podmieniamy ją jednym `RENAME`, więc sklep
     * przez cały czas liczenia podpowiada z poprzednich danych, a nie z połowy
     * nowych. Zamówienia idą paczkami po numerze, żeby żadne pojedyncze
     * zapytanie nie trzymało bazy długo.
     *
     * @return int liczba zapisanych par
     */
    public function rebuild(): int
    {
        $db = Db::getInstance();
        $docelowa = _DB_PREFIX_ . self::TABLE;
        $robocza = $docelowa . '_build';
        $stara = $docelowa . '_old';

        $db->execute('DROP TABLE IF EXISTS `' . $robocza . '`');
        $db->execute('DROP TABLE IF EXISTS `' . $stara . '`');
        $db->execute(self::definicja($robocza));
        self::installTable();

        $zakres = $db->getRow('SELECT MIN(id_order) AS od, MAX(id_order) AS do FROM `' . _DB_PREFIX_ . 'orders` WHERE valid = 1');
        $od = (int) ($zakres['od'] ?? 0);
        $do = (int) ($zakres['do'] ?? 0);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        for ($start = $od; $od > 0 && $start <= $do; $start += self::PACZKA) {
            $koniec = $start + self::PACZKA - 1;

            $db->execute(
                'INSERT INTO `' . $robocza . '` (id_shop, id_product, id_product_related, orders, last_order)
                 SELECT o.id_shop, od1.product_id, od2.product_id, COUNT(DISTINCT o.id_order), MAX(o.date_add)
                 FROM `' . _DB_PREFIX_ . 'orders` o
                 INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od1 ON od1.id_order = o.id_order
                 INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od2
                     ON od2.id_order = o.id_order AND od2.product_id <> od1.product_id
                 WHERE o.valid = 1 AND o.id_order BETWEEN ' . $start . ' AND ' . $koniec . '
                 GROUP BY o.id_shop, od1.product_id, od2.product_id
                 ON DUPLICATE KEY UPDATE
                     orders = orders + VALUES(orders),
                     last_order = GREATEST(COALESCE(last_order, VALUES(last_order)), VALUES(last_order))'
            );
        }

        $db->execute('RENAME TABLE `' . $docelowa . '` TO `' . $stara . '`, `' . $robocza . '` TO `' . $docelowa . '`');
        $db->execute('DROP TABLE IF EXISTS `' . $stara . '`');

        Configuration::updateGlobalValue(self::BUILT_AT, date('Y-m-d H:i:s'));

        return (int) $db->getValue('SELECT COUNT(*) FROM `' . $docelowa . '`');
    }

    /**
     * Stan do panelu: kiedy przeliczono, ile par i z ilu opłaconych zamówień.
     *
     * @return array{builtAt: string|null, pairs: int, orders: int}
     */
    public function summary(): array
    {
        $db = Db::getInstance();
        $zbudowano = Configuration::getGlobalValue(self::BUILT_AT);

        return [
            'builtAt' => $zbudowano ? (string) $zbudowano : null,
            'pairs' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE orders > 0'),
            'orders' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE valid = 1'),
        ];
    }

    private static function definicja(string $nazwa): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $nazwa . '` (
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_product` INT(11) UNSIGNED NOT NULL,
            `id_product_related` INT(11) UNSIGNED NOT NULL,
            `orders` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `last_order` DATETIME NULL,
            PRIMARY KEY (`id_shop`, `id_product`, `id_product_related`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }
}
