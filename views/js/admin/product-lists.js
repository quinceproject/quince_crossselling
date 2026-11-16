/**
 * Formularz produktu: wyszukiwarka w polach „Lista części zamiennych"
 * i „Lista elementów zestawu".
 *
 * Ta sama kontrolka rdzenia co „Produkty powiązane" (EntitySearchInput). Rdzeń
 * uruchamia ją tylko dla swoich pól, więc dla naszych robi to ten skrypt.
 * Znaczniki rysuje typ pola `ProductListType`.
 *
 * Po dodaniu albo usunięciu produktu wysyłamy `change` w polu wyszukiwarki:
 * formularz produktu włącza przycisk zapisu dopiero po zdarzeniu w którymś ze
 * swoich pól, a kontrolka dokłada pozycje jako ukryte pola bez zdarzenia.
 */
(function () {
    'use strict';

    function zgloszZmiane(pole) {
        var wejscie = pole.querySelector('input:not([type=hidden])') || pole.querySelector('input');

        if (wejscie && window.jQuery) {
            window.jQuery(wejscie).trigger('change');
        }
    }

    function uruchom() {
        var komponent = window.prestashop && window.prestashop.component && window.prestashop.component.EntitySearchInput;

        if (!komponent || !window.jQuery) {
            return false;
        }

        document.querySelectorAll('[data-quince-product-list]').forEach(function (pole) {
            if (pole.getAttribute('data-quince-product-list-ready') === '1') {
                return;
            }

            pole.setAttribute('data-quince-product-list-ready', '1');

            new komponent(window.jQuery(pole), {
                onSelectedContent: function () { zgloszZmiane(pole); },
                onRemovedContent: function () { zgloszZmiane(pole); }
            });
        });

        return true;
    }

    // Komponent rdzenia pojawia się z pakietem strony produktu, który bywa
    // ładowany po tym pliku: czekamy na niego krótko, zamiast zgadywać kolejność.
    function start() {
        var proby = 0;

        if (uruchom()) {
            return;
        }

        var zegar = window.setInterval(function () {
            proby += 1;

            if (uruchom() || proby > 50) {
                window.clearInterval(zegar);
            }
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
