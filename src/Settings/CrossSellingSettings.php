<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\CrossSelling\Settings;

use Configuration;
use Quince\Engine\Settings\ConfigStore;

/**
 * Ustawienia podpowiedzi produktów do dokupienia.
 *
 * Cztery miejsca, każde z własnym zestawem: karta produktu, koszyk, szuflada
 * koszyka i potwierdzenie zamówienia. Do tego dwie zasady wspólne dla wszystkich:
 * czy podpowiadać tylko to, co da się od razu kupić, i czy w koszyku dobierać
 * produkty pod próg darmowej dostawy.
 *
 * Na karcie produktu nie ma tu włącznika ani liczby kart: miejsce, liczbę
 * i wygląd karuzeli ustawia panel strony produktu, tak jak przy akcesoriach.
 */
final class CrossSellingSettings
{
    private const PREFIX = 'QUINCE_CROSS_';

    public const PLACEMENT_PRODUCT = 'PRODUCT';
    public const PLACEMENT_CART = 'CART';
    public const PLACEMENT_DRAWER = 'DRAWER';
    public const PLACEMENT_CONFIRMATION = 'CONFIRMATION';

    /** Historia zamówień: co klienci kupowali razem z tym produktem. */
    public const SOURCE_BOUGHT = 'bought';

    /** Akcesoria przypisane do produktu w katalogu. */
    public const SOURCE_ACCESSORIES = 'accessories';

    /** Produkty z kategorii domyślnej. */
    public const SOURCE_CATEGORY = 'category';

    /** Najlepiej sprzedające się produkty sklepu. */
    public const SOURCE_BESTSELLERS = 'bestsellers';

    /**
     * Źródła w kolejności, w jakiej dobór po nie sięga. Kolejność jest stała:
     * od najtrafniejszego do najogólniejszego. Sklep wybiera, które brać,
     * ale nie przestawia ich, bo „bestsellery przed akcesoriami" zawsze
     * podpowiadałoby gorzej.
     */
    public const SOURCES = [
        self::SOURCE_BOUGHT,
        self::SOURCE_ACCESSORIES,
        self::SOURCE_CATEGORY,
        self::SOURCE_BESTSELLERS,
    ];

    public const COUNT_MIN = 1;
    public const COUNT_MAX = 20;

    /** @var array<string,string> klucz => wartość domyślna */
    private const SCHEMA = [
        // --- zasady wspólne ---
        'PURCHASABLE_ONLY' => '1',
        'FREE_SHIPPING' => '1',

        // --- karta produktu ---
        // Bez akcesoriów i kategorii: na karcie produktu mają one własne sekcje
        // (akcesoria i „inne produkty z tej samej kategorii"), więc z pełną
        // kaskadą te same produkty stałyby w dwóch rzędach jeden pod drugim.
        'PRODUCT_TITLE' => '',
        'PRODUCT_SOURCES' => '["bought","bestsellers"]',

        // --- koszyk ---
        'CART_ENABLED' => '1',
        'CART_COUNT' => '8',
        'CART_TITLE' => '',
        'CART_SOURCES' => '["bought","accessories","category","bestsellers"]',

        // --- szuflada koszyka ---
        // Trzy, a nie osiem: w szufladzie podpowiedzi stoją pod listą pozycji
        // i każda zabiera cały wiersz.
        'DRAWER_ENABLED' => '1',
        'DRAWER_COUNT' => '3',
        'DRAWER_TITLE' => '',
        'DRAWER_SOURCES' => '["bought","accessories","category","bestsellers"]',

        // --- potwierdzenie zamówienia ---
        'CONFIRMATION_ENABLED' => '1',
        'CONFIRMATION_COUNT' => '8',
        'CONFIRMATION_TITLE' => '',
        'CONFIRMATION_SOURCES' => '["bought","accessories","category","bestsellers"]',
    ];

    /** Klucze wielojęzyczne: wartość trzymana jako JSON `{"1":"…","2":"…"}`. */
    private const TRANSLATABLE = ['PRODUCT_TITLE', 'CART_TITLE', 'DRAWER_TITLE', 'CONFIRMATION_TITLE'];

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $default) {
            $value = Configuration::get(self::PREFIX . $key);
            $out[$key] = ($value === false || $value === null) ? $default : (string) $value;
        }

        return $out;
    }

    /** @return string[] */
    public function configurationKeys(): array
    {
        return array_map(static fn (string $k): string => self::PREFIX . $k, array_keys(self::SCHEMA));
    }

    public function purchasableOnly(): bool
    {
        return $this->all()['PURCHASABLE_ONLY'] === '1';
    }

    public function freeShipping(): bool
    {
        return $this->all()['FREE_SHIPPING'] === '1';
    }

    /** Karta produktu jest zawsze „włączona"; o jej obecności decyduje panel strony produktu. */
    public function enabled(string $placement): bool
    {
        if ($placement === self::PLACEMENT_PRODUCT) {
            return true;
        }

        return ($this->all()[$placement . '_ENABLED'] ?? '0') === '1';
    }

    public function count(string $placement): int
    {
        $wartosc = (int) ($this->all()[$placement . '_COUNT'] ?? 8);

        return max(self::COUNT_MIN, min(self::COUNT_MAX, $wartosc));
    }

    /**
     * Źródła włączone dla miejsca, zawsze w stałej kolejności z SOURCES.
     *
     * @return string[]
     */
    public function sources(string $placement): array
    {
        $zapisane = json_decode($this->all()[$placement . '_SOURCES'] ?? '[]', true);
        $zapisane = is_array($zapisane) ? $zapisane : [];

        return array_values(array_filter(
            self::SOURCES,
            static fn (string $zrodlo): bool => in_array($zrodlo, $zapisane, true)
        ));
    }

    /** Tytuł sekcji w bieżącym języku, a bez tłumaczenia zapasowy. */
    public function title(string $placement, int $idLang, string $zapasowy): string
    {
        $wartosci = $this->decode($this->all()[$placement . '_TITLE'] ?? '');

        if (isset($wartosci[$idLang]) && trim((string) $wartosci[$idLang]) !== '') {
            return (string) $wartosci[$idLang];
        }

        return $zapasowy;
    }

    /**
     * Teksty wielojęzyczne do pól formularza.
     *
     * @return array<string,array<int,string>>
     */
    public function translations(): array
    {
        $all = $this->all();
        $out = [];

        foreach (self::TRANSLATABLE as $key) {
            $out[$key] = $this->decode($all[$key]);
        }

        return $out;
    }

    /**
     * Źródła każdego miejsca do zaznaczeń w formularzu.
     *
     * @return array<string,string[]>
     */
    public function sourcesByPlacement(): array
    {
        $out = [];

        foreach ([self::PLACEMENT_PRODUCT, self::PLACEMENT_CART, self::PLACEMENT_DRAWER, self::PLACEMENT_CONFIRMATION] as $miejsce) {
            $out[$miejsce] = $this->sources($miejsce);
        }

        return $out;
    }

    /**
     * Wartości początkowe przy instalacji: tytuły w językach sklepu.
     *
     * Zapis także do wiersza globalnego, żeby sklep założony później i panel
     * otwarty na „wszystkich sklepach" dostały te same teksty.
     *
     * @param array<string,array<int,string>> $teksty klucz => (id języka => tekst)
     */
    public function seed(array $teksty): void
    {
        foreach (self::TRANSLATABLE as $klucz) {
            if (!empty($teksty[$klucz])) {
                $json = (string) json_encode($teksty[$klucz]);
                ConfigStore::set(self::PREFIX . $klucz, $json, true);
                Configuration::updateGlobalValue(self::PREFIX . $klucz, $json, true);
            }
        }
    }

    /**
     * Zapis formularza.
     *
     * @param array<string,mixed> $values
     */
    public function save(array $values): void
    {
        foreach (['PURCHASABLE_ONLY', 'FREE_SHIPPING', 'CART_ENABLED', 'DRAWER_ENABLED', 'CONFIRMATION_ENABLED'] as $key) {
            if (array_key_exists($key, $values)) {
                ConfigStore::set(self::PREFIX . $key, ((string) $values[$key]) === '1' ? '1' : '0');
            }
        }

        foreach (['CART_COUNT', 'DRAWER_COUNT', 'CONFIRMATION_COUNT'] as $key) {
            if (array_key_exists($key, $values)) {
                $liczba = max(self::COUNT_MIN, min(self::COUNT_MAX, (int) $values[$key]));
                ConfigStore::set(self::PREFIX . $key, (string) $liczba);
            }
        }

        // Źródła przychodzą listą zaznaczeń. Znacznik wysyłki odróżnia „nic nie
        // zaznaczono" od „tego pola w formularzu nie było": bez niego nie dałoby
        // się odznaczyć ostatniego źródła.
        foreach ([self::PLACEMENT_PRODUCT, self::PLACEMENT_CART, self::PLACEMENT_DRAWER, self::PLACEMENT_CONFIRMATION] as $miejsce) {
            $klucz = $miejsce . '_SOURCES';

            if (!array_key_exists($klucz . '_SUBMITTED', $values)) {
                continue;
            }

            $wybrane = $values[$klucz] ?? [];
            $wybrane = is_array($wybrane) ? array_map('strval', $wybrane) : [];
            $wybrane = array_values(array_filter(
                self::SOURCES,
                static fn (string $zrodlo): bool => in_array($zrodlo, $wybrane, true)
            ));

            ConfigStore::set(self::PREFIX . $klucz, (string) json_encode($wybrane), true);
        }

        // Pola wielojęzyczne przychodzą jako CART_TITLE_1, _2 … i lądują w jednym
        // wpisie JSON. isHtml = true, bo bez tego PrestaShop wycina cudzysłowy.
        foreach (self::TRANSLATABLE as $key) {
            $zebrane = [];

            foreach ($values as $name => $value) {
                if (preg_match('/^' . $key . '_(\d+)$/', (string) $name, $m)) {
                    $zebrane[(int) $m[1]] = (string) $value;
                }
            }

            if ($zebrane !== []) {
                ConfigStore::set(self::PREFIX . $key, (string) json_encode($zebrane), true);
            }
        }
    }

    /** @return array<int,string> */
    private function decode(string $raw): array
    {
        $odczytane = json_decode($raw, true);

        return is_array($odczytane) ? $odczytane : [];
    }
}
