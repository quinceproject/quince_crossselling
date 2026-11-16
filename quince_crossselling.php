<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use Quince\CrossSelling\Lists\ProductLists;
use Quince\CrossSelling\Recommendation\BoughtTogether;
use Quince\CrossSelling\Recommendation\Recommender;
use Quince\CrossSelling\Settings\CrossSellingSettings;

/**
 * Quince Cross-selling: podpowiedzi produktów do dokupienia.
 *
 * Cztery miejsca: karta produktu, koszyk, szuflada koszyka i potwierdzenie
 * zamówienia. Wszystkie korzystają z jednego doboru (`Recommender`), a różnią
 * się ustawieniami i wyglądem: na karcie produktu, w koszyku i na potwierdzeniu
 * stoi karuzela motywu, w szufladzie koszyka krótka lista wierszy.
 *
 * Moduł zgłasza się silnikowi hookiem `actionQuinceRegisterFeature`, więc
 * element „Products to add" w panelu strony produktu ożywa dopiero wtedy, gdy
 * moduł jest zainstalowany.
 */
class Quince_Crossselling extends Module
{
    private const DOMENA_PANELU = 'Modules.Quincecrossselling.Admin';
    private const DOMENA_SKLEPU = 'Modules.Quincecrossselling.Shop';

    /** Karuzela pod kartą produktu, w miejscu ustawionym w panelu strony produktu. */
    public const HOOK_PRODUCT = 'displayQuinceCrossSelling';

    /** Lista w szufladzie koszyka, pod pozycjami koszyka. */
    public const HOOK_DRAWER = 'displayQuinceCartUpsell';

    /** Krótka lista pod pozycjami na stronie koszyka, obok karuzeli pod spodem. */
    public const HOOK_CART_SUGGEST = 'displayQuinceCartSuggest';

    /**
     * Karuzele list wpisanych przy produkcie: części zamienne, „pasuje do",
     * elementy zestawu, „w zestawach". Którą rysować, mówi parametr qLista.
     */
    public const HOOK_LISTS = 'displayQuinceProductList';

    /**
     * Karuzele list: pole produktu, kierunek i domyślny tytuł.
     *
     * @var array<string,array{0:string,1:bool,2:string}>
     */
    private const LISTS = [
        CrossSellingSettings::SOURCE_SPARE_PARTS => [ProductLists::SPARE_PARTS, false, 'Spare parts'],
        CrossSellingSettings::SOURCE_SPARE_PARTS_FOR => [ProductLists::SPARE_PARTS, true, 'Fits'],
        CrossSellingSettings::SOURCE_SET_ITEMS => [ProductLists::SET_ITEMS, false, 'Set items'],
        CrossSellingSettings::SOURCE_IN_SETS => [ProductLists::SET_ITEMS, true, 'In sets'],
    ];

    public function __construct()
    {
        $this->name = 'quince_crossselling';
        $this->tab = 'front_office_features';
        $this->version = '0.3.0';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];

        // Silnik nie jest opcjonalny: to jego panel udostępnia ustawienia, jego
        // mapa strony produktu decyduje o miejscu karuzeli, a jego motyw ją rysuje.
        $this->dependencies = ['quince_engine'];

        $this->tabs = [
            [
                'class_name' => 'AdminQuinceCrossSelling',
                'visible' => true,
                'name' => 'Cross-selling',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_crossselling_settings',
                'wording' => 'Cross-selling',
                'wording_domain' => self::DOMENA_PANELU,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Cross-selling', [], self::DOMENA_PANELU);
        $this->description = $this->trans(
            'Products to add on the product page, in the cart and after the order, picked from order history, accessories, category and best sellers. Requires Quince Engine.',
            [],
            self::DOMENA_PANELU
        );
    }

    public function install(): bool
    {
        return parent::install()
            && BoughtTogether::installTable()
            && $this->zalozHooki()
            && $this->registerHook($this->hooks())
            && $this->installModuleGroups()
            && $this->setDefaults()
            && $this->installListFields()
            && $this->policzParyZHistorii();
    }

    public function uninstall(): bool
    {
        foreach ((new CrossSellingSettings())->configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        // Definicje pól znikają, wpisane numery zostają: ponowna instalacja
        // przywraca listy bez wysyłania ich jeszcze raz.
        foreach (ProductLists::FIELDS as $pole) {
            $this->unregisterExtraProperty(new \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition(
                entityName: 'product',
                propertyName: $pole,
                type: \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType::STRING,
                scope: \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope::SHOP,
                moduleName: $this->name
            ), false);
        }

        return BoughtTogether::dropTable() && parent::uninstall();
    }

    /**
     * Pola produktu na części zamienne i elementy zestawu.
     *
     * Pole dodatkowe rdzenia: stoi w formularzu produktu za akcesoriami, wchodzi
     * do Admin API produktu, więc katalog wysyła listy razem z produktem. Zakres
     * sklepowy, bo numer artykułu nie zależy od języka.
     *
     * Publiczne, bo woła je też aktualizacja 0.3.0.
     */
    public function installListFields(): bool
    {
        $pola = [
            // Etykiety inne niż nazwy źródeł: `labelWording` nadpisuje tłumaczenie
            // tego samego napisu w domenie modułu.
            ProductLists::SPARE_PARTS => [
                'Spare parts list',
                'Item numbers (SKU) of spare parts for this product, separated by commas, in the order to show. A number the shop does not have yet shows up once that product is added.',
            ],
            ProductLists::SET_ITEMS => [
                'Set items list',
                'Item numbers (SKU) of the items in this set, separated by commas, in the order to show. This does not make the product a pack: its stock and price stay its own.',
            ],
        ];

        // Każde pole staje zaraz za akcesoriami, więc ostatnie zarejestrowane
        // stoi najwyżej. Odwrotna kolejność daje części nad elementami zestawu.
        foreach (array_reverse($pola, true) as $pole => [$etykieta, $opis]) {
            $zapisane = $this->registerExtraProperty(new \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyDefinition(
                entityName: 'product',
                propertyName: $pole,
                type: \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyType::STRING,
                scope: \PrestaShop\PrestaShop\Core\ExtraProperty\Definition\ExtraPropertyScope::SHOP,
                size: ProductLists::FIELD_SIZE,
                displayFront: false,
                associatedForms: ['product:description.related_products:after'],
                associatedApis: ['/products', '/products/{productId}'],
                labelWording: $etykieta,
                labelDomain: self::DOMENA_PANELU,
                descriptionWording: $opis,
                descriptionDomain: self::DOMENA_PANELU
            ));

            if ($zapisane === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pary „kupowane razem" z zamówień, które sklep już ma. Bez tego źródło
     * zaczynałoby od zera i zapełniało się dopiero z nowymi zamówieniami.
     */
    private function policzParyZHistorii(): bool
    {
        (new BoughtTogether())->rebuild();

        return true;
    }

    /** @return string[] */
    private function hooks(): array
    {
        return [
            'actionQuinceRegisterFeature',
            'actionFrontControllerSetVariables',
            self::HOOK_PRODUCT,
            self::HOOK_DRAWER,
            self::HOOK_CART_SUGGEST,
            self::HOOK_LISTS,
            'displayCrossSellingShoppingCart',
            'displayOrderConfirmation2',

            // Pary „kupowane razem": zamówienie opłacone dokłada swoje, anulowane odejmuje.
            'actionOrderStatusPostUpdate',
        ];
    }

    /**
     * Ogłoszenie własnych miejsc.
     *
     * `Hook::add()` przed rejestracją: PrestaShop po cichu pomija rejestrację
     * na hook, którego nie ma jeszcze w tabeli.
     */
    private function zalozHooki(): bool
    {
        $miejsca = [
            self::HOOK_PRODUCT => [
                'Quince:produkty do dokupienia na karcie produktu',
                'Karuzela pod kartą produktu. Motyw podaje parametry qIle i qStrzalki z panelu strony produktu.',
            ],
            self::HOOK_DRAWER => [
                'Quince:dokupka w szufladzie koszyka',
                'Lista „Może Ci się przydać" pod pozycjami w szufladzie koszyka.',
            ],
            self::HOOK_CART_SUGGEST => [
                'Quince:dokupka na stronie koszyka',
                'Krótka lista „Nie zapomnij" pod pozycjami na stronie koszyka.',
            ],
            self::HOOK_LISTS => [
                'Quince:listy produktu na karcie produktu',
                'Części zamienne, pasuje do, elementy zestawu, w zestawach. Motyw podaje qLista, qIle, qStrzalki i qTytul z panelu strony produktu.',
            ],
        ];

        foreach ($miejsca as $nazwa => [$tytul, $opis]) {
            if (Hook::getIdByName($nazwa)) {
                continue;
            }

            $hook = new Hook();
            $hook->name = $nazwa;
            $hook->title = $tytul;
            $hook->description = $opis;
            $hook->position = false;

            if (!$hook->add()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wpisy w ps_module_group.
     *
     * Bez nich hook frontendowy w ogóle nie odpala: PrestaShop dokłada do listy
     * modułów warunek na grupę klienta i moduł bez wpisów znika z wyników.
     */
    private function installModuleGroups(): bool
    {
        $grupy = Group::getGroups((int) Context::getContext()->language->id, true);

        foreach (Shop::getShops(true, null, true) as $idShop) {
            foreach ($grupy as $grupa) {
                Db::getInstance()->insert(
                    'module_group',
                    [
                        'id_module' => (int) $this->id,
                        'id_shop' => (int) $idShop,
                        'id_group' => (int) $grupa['id_group'],
                    ],
                    false,
                    true,
                    Db::INSERT_IGNORE
                );
            }
        }

        return true;
    }

    /** Tytuły sekcji w językach sklepu. */
    private function setDefaults(): bool
    {
        $teksty = [];

        // Tytuł tłumaczony na język, dla którego go zapisujemy. Bez podanego
        // języka tłumaczenie szło na język panelu i każdy język dostawał
        // angielski tytuł.
        foreach (Language::getLanguages(false) as $jezyk) {
            $idLang = (int) $jezyk['id_lang'];
            $tytul = $this->trans('You might also need', [], self::DOMENA_SKLEPU, (string) $jezyk['locale']);

            foreach (['PRODUCT_TITLE', 'CART_TITLE', 'DRAWER_TITLE', 'CONFIRMATION_TITLE'] as $klucz) {
                $teksty[$klucz][$idLang] = $tytul;
            }
        }

        (new CrossSellingSettings())->seed($teksty);

        return true;
    }

    /**
     * Zgłoszenie do silnika: co dostarczamy i gdzie są nasze ustawienia.
     *
     * @return array<int,array<string,string>>
     */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'cross_selling',
            'id' => 'quince_crossselling',
            'label' => $this->trans('Cross-selling', [], self::DOMENA_PANELU),
            'route' => 'quince_crossselling_settings',
        ]];
    }

    /**
     * Znak dla motywu, że moduł działa. Motyw wyłącza wtedy natywny blok
     * „Klienci kupili również" ze stopki produktu, żeby te same produkty
     * nie stały w dwóch sekcjach jedna pod drugą.
     *
     * @return array<string,bool>
     */
    public function hookActionFrontControllerSetVariables(): array
    {
        return ['active' => true];
    }

    /**
     * Zmiana statusu zamówienia: pary „kupowane razem" idą za tym, czy zamówienie
     * jest ważne, czyli tym samym, co rdzeń zapisuje w `orders.valid`.
     *
     * Nowe zamówienie nie ma jeszcze statusu poprzedniego (identyfikator 0),
     * więc liczy się jako nieważne: zamówienie opłacone od razu dokłada pary
     * przy pierwszym statusie.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $nowy = $params['newOrderStatus'] ?? null;
        $stary = $params['oldOrderStatus'] ?? null;

        if (!$nowy instanceof OrderState || empty($params['id_order'])) {
            return;
        }

        $bylWazny = $stary instanceof OrderState && Validate::isLoadedObject($stary) && (bool) $stary->logable;
        $jestWazny = (bool) $nowy->logable;

        if ($bylWazny === $jestWazny) {
            return;
        }

        (new BoughtTogether())->applyOrder((int) $params['id_order'], $jestWazny ? 1 : -1);
    }

    // =========================================================================
    // Miejsca
    // =========================================================================

    /** Karta produktu: karuzela w strefie ustawionej w panelu strony produktu. */
    public function hookDisplayQuinceCrossSelling(array $params): string
    {
        $idProduct = $this->idProduktu($params['product'] ?? null);

        if ($idProduct <= 0) {
            return '';
        }

        $ustawienia = new CrossSellingSettings();
        $ile = max(1, (int) ($params['qIle'] ?? 8));
        $zrodla = $ustawienia->sources(CrossSellingSettings::PLACEMENT_PRODUCT);
        $pomin = $this->produktyKoszyka();

        // Akcesoria mają na karcie produktu własną sekcję. Gdy sklep nie wziął
        // ich tu jako źródła, nie wracają też inną drogą: produkt bywa naraz
        // akcesorium i „kupowanym razem", a wtedy stałby w dwóch rzędach.
        if (!in_array(CrossSellingSettings::SOURCE_ACCESSORIES, $zrodla, true)) {
            $pomin = array_merge($pomin, $this->akcesoria($idProduct));
        }

        // To samo z listami produktu: części i elementy zestawu mają własne
        // karuzele, więc bez wyboru jako źródło nie wracają tu inną drogą.
        foreach (self::LISTS as $zrodlo => [$pole, $odwrotnie]) {
            if (!in_array($zrodlo, $zrodla, true)) {
                $pomin = array_merge($pomin, $this->zListy($idProduct, $pole, $odwrotnie));
            }
        }

        $ids = (new Recommender())->recommend(
            [$idProduct],
            $pomin,
            $zrodla,
            $ile,
            $ustawienia->purchasableOnly()
        );

        $parametry = [
            'qcsIle' => $ile,
            'qcsStrzalki' => (bool) ($params['qStrzalki'] ?? true),
            'qcsKontener' => false,
            'qcsPodtytul' => '',
        ];

        // Tytuł ustawia ekran strony produktu w silniku. Pusty zostawia tytuł
        // zapisany w ustawieniach tego modułu.
        if (trim((string) ($params['qTytul'] ?? '')) !== '') {
            $parametry['qcsTytul'] = trim((string) $params['qTytul']);
        }

        return $this->karuzela($ids, 'crossselling', CrossSellingSettings::PLACEMENT_PRODUCT, $parametry);
    }

    /**
     * Karta produktu: karuzela jednej z list wpisanych przy produkcie.
     *
     * Pokazuje produkty widoczne w sklepie, także niedostępne do zamówienia:
     * przy części zamiennej klient ma zobaczyć, że istnieje, i jej termin.
     */
    public function hookDisplayQuinceProductList(array $params): string
    {
        $lista = (string) ($params['qLista'] ?? '');
        $idProduct = $this->idProduktu($params['product'] ?? null);

        if ($idProduct <= 0 || !isset(self::LISTS[$lista])) {
            return '';
        }

        [$pole, $odwrotnie, $domyslny] = self::LISTS[$lista];
        $ile = max(1, (int) ($params['qIle'] ?? 8));
        $ids = array_slice((new Recommender())->visible($this->zListy($idProduct, $pole, $odwrotnie)), 0, $ile);
        $tytul = trim((string) ($params['qTytul'] ?? ''));

        return $this->karuzela($ids, str_replace('_', '-', $lista), CrossSellingSettings::PLACEMENT_PRODUCT, [
            'qcsIle' => $ile,
            'qcsStrzalki' => (bool) ($params['qStrzalki'] ?? true),
            'qcsKontener' => false,
            'qcsPodtytul' => '',
            'qcsTytul' => $tytul !== '' ? $tytul : $this->trans($domyslny, [], self::DOMENA_SKLEPU),
        ]);
    }

    /** @return int[] */
    private function zListy(int $idProduct, string $pole, bool $odwrotnie): array
    {
        $listy = new ProductLists();
        $idShop = (int) $this->context->shop->id;

        return $odwrotnie
            ? $listy->listing([$idProduct], $pole, $idShop)
            : $listy->listed([$idProduct], $pole, $idShop);
    }

    /** Strona koszyka: karuzela pod koszykiem. */
    public function hookDisplayCrossSellingShoppingCart(): string
    {
        $ustawienia = new CrossSellingSettings();

        if (!$ustawienia->enabled(CrossSellingSettings::PLACEMENT_CART)) {
            return '';
        }

        $wKoszyku = $this->produktyKoszyka();

        if ($wKoszyku === []) {
            return '';
        }

        $brak = $this->brakDoDarmowejDostawy($ustawienia);
        $ile = $ustawienia->count(CrossSellingSettings::PLACEMENT_CART);

        $ids = (new Recommender())->recommend(
            $wKoszyku,
            $wKoszyku,
            $ustawienia->sources(CrossSellingSettings::PLACEMENT_CART),
            $ile,
            $ustawienia->purchasableOnly(),
            $brak
        );

        return $this->karuzela($ids, 'cart-crossselling', CrossSellingSettings::PLACEMENT_CART, [
            'qcsIle' => $ile,
            'qcsStrzalki' => true,
            'qcsKontener' => false,
            'qcsPodtytul' => $this->zdanieOProgu($brak),
        ]);
    }

    /** Szuflada koszyka: krótka lista pod pozycjami koszyka. */
    public function hookDisplayQuinceCartUpsell(): string
    {
        $ustawienia = new CrossSellingSettings();

        if (!$ustawienia->enabled(CrossSellingSettings::PLACEMENT_DRAWER)) {
            return '';
        }

        $wKoszyku = $this->produktyKoszyka();

        if ($wKoszyku === []) {
            return '';
        }

        $brak = $this->brakDoDarmowejDostawy($ustawienia);

        $ids = (new Recommender())->recommend(
            $wKoszyku,
            $wKoszyku,
            $ustawienia->sources(CrossSellingSettings::PLACEMENT_DRAWER),
            $ustawienia->count(CrossSellingSettings::PLACEMENT_DRAWER),
            $ustawienia->purchasableOnly(),
            $brak
        );

        $produkty = $this->zajawki($ids);

        if ($produkty === []) {
            return '';
        }

        // Bez zdania o progu: w szufladzie stoi nad listą pasek darmowej dostawy
        // z tą samą kwotą.
        $this->context->smarty->assign([
            'qcsProdukty' => $produkty,
            'qcsTytul' => $this->tytul($ustawienia, CrossSellingSettings::PLACEMENT_DRAWER),
        ]);

        return $this->fetch('module:quince_crossselling/views/templates/hook/upsell.tpl');
    }

    /** Strona koszyka: krótka lista pod pozycjami koszyka, obok karuzeli pod spodem. */
    public function hookDisplayQuinceCartSuggest(): string
    {
        $ustawienia = new CrossSellingSettings();

        if (!$ustawienia->enabled(CrossSellingSettings::PLACEMENT_CART)) {
            return '';
        }

        $wKoszyku = $this->produktyKoszyka();

        if ($wKoszyku === []) {
            return '';
        }

        $brak = $this->brakDoDarmowejDostawy($ustawienia);

        $ids = (new Recommender())->recommend(
            $wKoszyku,
            $wKoszyku,
            $ustawienia->sources(CrossSellingSettings::PLACEMENT_CART),
            $ustawienia->count(CrossSellingSettings::PLACEMENT_CART),
            $ustawienia->purchasableOnly(),
            $brak
        );

        $produkty = $this->zajawki($ids);

        if ($produkty === []) {
            return '';
        }

        $this->context->smarty->assign([
            'qcsProdukty' => $produkty,
            'qcsTytul' => $this->tytul($ustawienia, CrossSellingSettings::PLACEMENT_CART),
        ]);

        return $this->fetch('module:quince_crossselling/views/templates/hook/cart-suggest.tpl');
    }

    /**
     * Potwierdzenie zamówienia: karuzela z produktami do tego, co klient właśnie
     * kupił. Bez produktów z tego zamówienia.
     */
    public function hookDisplayOrderConfirmation2(array $params): string
    {
        $ustawienia = new CrossSellingSettings();

        if (!$ustawienia->enabled(CrossSellingSettings::PLACEMENT_CONFIRMATION)) {
            return '';
        }

        $zamowienie = $params['order'] ?? $this->zamowienieZAdresu();

        if (!$zamowienie instanceof Order || !Validate::isLoadedObject($zamowienie)) {
            return '';
        }

        $kupione = array_values(array_unique(array_map(
            static fn (array $pozycja): int => (int) $pozycja['product_id'],
            $zamowienie->getProducts()
        )));

        if ($kupione === []) {
            return '';
        }

        $ile = $ustawienia->count(CrossSellingSettings::PLACEMENT_CONFIRMATION);

        $ids = (new Recommender())->recommend(
            $kupione,
            $kupione,
            $ustawienia->sources(CrossSellingSettings::PLACEMENT_CONFIRMATION),
            $ile,
            $ustawienia->purchasableOnly()
        );

        return $this->karuzela($ids, 'confirmation-crossselling', CrossSellingSettings::PLACEMENT_CONFIRMATION, [
            'qcsIle' => $ile,
            'qcsStrzalki' => true,
            // Strona potwierdzenia nie stoi w karcie produktu ani w koszyku:
            // bez własnego kontenera ta karuzela wychodziła szerzej niż
            // "Featured products" obok niej, o szerokość `.q-container`.
            'qcsKontener' => true,
            'qcsPodtytul' => '',
        ]);
    }

    // =========================================================================
    // Wnętrze
    // =========================================================================

    /**
     * Karuzela motywu z podpowiedziami.
     *
     * @param int[]               $ids
     * @param array<string,mixed> $parametry
     */
    private function karuzela(array $ids, string $id, string $miejsce, array $parametry): string
    {
        $produkty = $this->zajawki($ids);

        if ($produkty === []) {
            return '';
        }

        $this->context->smarty->assign($parametry + [
            'qcsProdukty' => $produkty,
            'qcsId' => $id,
            'qcsTytul' => $this->tytul(new CrossSellingSettings(), $miejsce),
        ]);

        return $this->fetch('module:quince_crossselling/views/templates/hook/carousel.tpl');
    }

    private function tytul(CrossSellingSettings $ustawienia, string $miejsce): string
    {
        return $ustawienia->title(
            $miejsce,
            (int) $this->context->language->id,
            $this->trans('You might also need', [], self::DOMENA_SKLEPU)
        );
    }

    /**
     * Produkty w postaci, której oczekuje zajawka motywu: ta sama, co na
     * listingu, żeby karta wyglądała wszędzie tak samo.
     *
     * @param int[] $ids
     *
     * @return array<int,mixed>
     */
    private function zajawki(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $assembler = new ProductAssembler($this->context);
        $ustawieniaPrezentacji = (new ProductPresenterFactory($this->context))->getPresentationSettings();
        $presenter = new ProductListingPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );

        $surowe = $assembler->assembleProducts(array_map(
            static fn (int $id): array => ['id_product' => $id],
            $ids
        ));

        $wynik = [];

        foreach ($surowe as $surowy) {
            $wynik[] = $presenter->present($ustawieniaPrezentacji, $surowy, $this->context->language);
        }

        return $wynik;
    }

    /**
     * Zamówienie ze strony potwierdzenia.
     *
     * Motywy wołają `displayOrderConfirmation2` bez parametrów, a kontroler trzyma
     * zamówienie w polu chronionym. Czytamy je więc z adresu tak jak kontroler:
     * po koszyku i z tym samym kluczem, żeby nikt nie podejrzał cudzego zamówienia.
     */
    private function zamowienieZAdresu(): ?Order
    {
        if (!$this->context->controller instanceof OrderConfirmationController) {
            return null;
        }

        $idOrder = (int) Order::getIdByCartId((int) Tools::getValue('id_cart'));
        $klucz = (string) Tools::getValue('key');

        if ($idOrder <= 0 || $klucz === '') {
            return null;
        }

        $zamowienie = new Order($idOrder);

        return Validate::isLoadedObject($zamowienie) && $zamowienie->secure_key === $klucz ? $zamowienie : null;
    }

    /** @return int[] */
    private function akcesoria(int $idProduct): array
    {
        $wiersze = Db::getInstance()->executeS(
            'SELECT id_product_2 FROM `' . _DB_PREFIX_ . 'accessory` WHERE id_product_1 = ' . $idProduct
        );

        return is_array($wiersze) ? array_map(static fn (array $w): int => (int) $w['id_product_2'], $wiersze) : [];
    }

    /** @return int[] */
    private function produktyKoszyka(): array
    {
        $koszyk = $this->context->cart;

        if (!$koszyk || !$koszyk->id) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (array $pozycja): int => (int) $pozycja['id_product'],
            $koszyk->getProducts()
        )));
    }

    /**
     * Kwota brakująca do darmowej dostawy albo null, gdy tryb nie działa: jest
     * wyłączony, sklep nie ma progu albo koszyk już go przekroczył.
     *
     * Wyliczenie z silnika, to samo, z którego szuflada koszyka rysuje pasek
     * postępu: dobór pod próg i pasek nie mogą mówić o dwóch różnych kwotach.
     */
    private function brakDoDarmowejDostawy(CrossSellingSettings $ustawienia): ?float
    {
        if (!$ustawienia->freeShipping()) {
            return null;
        }

        $stan = \Quince\Engine\Cart\FreeShipping::forContext($this->context);

        return $stan !== null && !$stan['reached'] ? $stan['missing'] : null;
    }

    /** Zdanie pod tytułem: ile brakuje do darmowej dostawy. */
    private function zdanieOProgu(?float $brak): string
    {
        if ($brak === null) {
            return '';
        }

        $kwota = $this->context->getCurrentLocale()->formatPrice($brak, $this->context->currency->iso_code);

        return $this->trans('Add %amount% more to get free delivery.', ['%amount%' => $kwota], self::DOMENA_SKLEPU);
    }

    /** Identyfikator produktu z parametru hooka: tablicy z prezentera albo obiektu. */
    private function idProduktu($produkt): int
    {
        if ($produkt instanceof Product) {
            return (int) $produkt->id;
        }

        if (is_array($produkt) || $produkt instanceof ArrayAccess) {
            return (int) ($produkt['id_product'] ?? 0);
        }

        return 0;
    }
}
