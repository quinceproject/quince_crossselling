{**
 * Quince Cross-selling: dokupka w szufladzie koszyka.
 *
 * W szufladzie nie mieści się karuzela, więc podpowiedzi idą listą krótkich
 * wierszy: miniatura, nazwa z ceną i przycisk dodania. Dodanie robi skrypt
 * rdzenia, jak przy każdym przycisku `add-to-cart`: bierze formularz przycisku,
 * dlatego wiersz jest formularzem.
 *
 * Miniatura w ramce pozycji koszyka (`q-cartline__media`), jak w projekcie.
 *
 * Projekt: makiety/strona-produktu-alt.html, szuflada koszyka, „Może Ci się
 * przydać". Wygląd: themes/quince/assets/css/components/upsell.css
 *}

<div class="q-upsell">
  <div class="q-upsell__title">{$qcsTytul}</div>

  {foreach from=$qcsProdukty item=qcsProdukt}
    <form class="q-upsell__item" action="{$urls.pages.cart}" method="post">
      <input type="hidden" name="token" value="{$static_token}">
      <input type="hidden" name="id_product" value="{$qcsProdukt.id_product|intval}">
      <input type="hidden" name="id_product_attribute" value="{$qcsProdukt.id_product_attribute|intval}">
      <input type="hidden" name="qty" value="1">

      <span class="q-cartline__media">
        <img src="{if $qcsProdukt.cover}{$qcsProdukt.cover.bySize.cart_default.url}{else}{$urls.no_picture_image.bySize.cart_default.url}{/if}"
             alt="" width="125" height="125" loading="lazy" decoding="async">
      </span>

      <span class="q-upsell__name">
        {$qcsProdukt.name}
        {if $qcsProdukt.show_price}<span class="q-upsell__price">{$qcsProdukt.price}</span>{/if}
      </span>

      {if $qcsProdukt.add_to_cart_url}
        <button class="q-btn q-btn--secondary q-btn--sm" type="submit" data-button-action="add-to-cart">
          {l s='Add' d='Shop.Theme.Actions'}
        </button>
      {/if}
    </form>
  {/foreach}
</div>
