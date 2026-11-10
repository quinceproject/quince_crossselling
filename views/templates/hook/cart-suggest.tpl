{**
 * Quince Cross-selling — dokupka na stronie koszyka.
 *
 * Krótka lista pod pozycjami koszyka, obok karuzeli pod spodem (osobne
 * ustawienie, `PLACEMENT_CART`). Dodanie robi skrypt rdzenia, jak przy
 * każdym przycisku `add-to-cart`: bierze formularz przycisku, dlatego
 * wiersz jest formularzem.
 *
 * Projekt: makiety/koszyk.html, sekcja „Nie zapomnij".
 * Wygląd: themes/quince/assets/css/components/cart.css
 *}

<section class="q-cart__suggest">
  <h2 class="q-cart__suggest-title">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 6a6 6 0 1 1 10.174 4.31c-.203.196-.359.4-.453.619l-.762 1.769A.5.5 0 0 1 10.5 13a.5.5 0 0 1 0 1 .5.5 0 0 1 0 1l-.224.447a1 1 0 0 1-.894.553H6.618a1 1 0 0 1-.894-.553L5.5 15a.5.5 0 0 1 0-1 .5.5 0 0 1 0-1 .5.5 0 0 1-.46-.302l-.761-1.77a2 2 0 0 0-.453-.618A5.98 5.98 0 0 1 2 6"/></svg>
    {$qcsTytul}
  </h2>

  {foreach from=$qcsProdukty item=qcsProdukt}
    <form class="q-cart__suggest-item" action="{$urls.pages.cart}" method="post">
      <input type="hidden" name="token" value="{$static_token}">
      <input type="hidden" name="id_product" value="{$qcsProdukt.id_product|intval}">
      <input type="hidden" name="id_product_attribute" value="{$qcsProdukt.id_product_attribute|intval}">
      <input type="hidden" name="qty" value="1">

      <span class="q-cart__suggest-media">
        <img src="{if $qcsProdukt.cover}{$qcsProdukt.cover.bySize.cart_default.url}{else}{$urls.no_picture_image.bySize.cart_default.url}{/if}"
             alt="" loading="lazy" decoding="async">
      </span>

      <div class="q-cart__suggest-body">
        <a class="q-cart__suggest-name" href="{$qcsProdukt.url}">{$qcsProdukt.name}</a>
        {if $qcsProdukt.show_price}
          <div class="q-cart__suggest-meta">
            <span class="q-price"><span class="q-price__current">{$qcsProdukt.price}</span></span>
          </div>
        {/if}
      </div>

      {if $qcsProdukt.add_to_cart_url}
        <button class="q-btn q-btn--secondary" type="submit" data-button-action="add-to-cart">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2"/></svg>
          {l s='Add' d='Shop.Theme.Actions'}
        </button>
      {/if}
    </form>
  {/foreach}
</section>
