# Quince Cross-selling

**Products to add on the product page, in the cart and after the order, for PrestaShop 9.2.**

Quince Cross-selling suggests products to add in four places: under the product, on
the cart page, in the cart drawer and under the order confirmation. It picks them from
what customers bought together in past orders, the product accessories, the same
category and the best sellers, and leaves out what cannot be ordered.

> Quince Cross-selling is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Four places.** A carousel under the product, a row of products under the cart, a
  short list with an add button in the cart drawer and a row under the order summary.
  Each place has its own settings.
- **Four sources, in a fixed order.** Bought together in past orders, accessories of
  the product, the same category and the best sellers. The shop takes products in
  this order until it has enough, and each place can use fewer sources to keep the
  suggestions closer to the product.
- **Bought together from real orders.** On install the module counts the pairs of
  products in the paid orders the shop already has. After that every paid or cancelled
  order updates them by itself. A button recalculates everything, for example after
  importing orders from another shop.
- **Only what can be bought.** Products out of stock, with the price on request or not
  available for ordering are left out. Products allowed to be ordered without stock
  still show.
- **Closer to free delivery.** While the cart is below the free delivery threshold
  (Shipping → Preferences), the cheapest products that close the gap come first, and a
  line under the heading says how much is missing.
- **Per place settings.** Number of products (1 to 20) and an optional heading in every
  language. On the product page the position of the section, its number of products and
  its arrows are set in the Quince Engine product page screen.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's
  standard translation system.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

Quince Engine hosts the settings screen, places the product page section and its
theme draws the carousels. The module declares it as a dependency, so PrestaShop
refuses to install Quince Cross-selling without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_crossselling.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_crossselling/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_crossselling` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Cross-selling and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/crossselling:*"
php ../bin/console prestashop:module install quince_crossselling
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_crossselling/releases).

Uninstalling the module removes its settings and the bought together pairs.

## Configuration

Open **Quince project → Cross-selling** in the back office menu.

1. **Which products can be suggested.** Switch on the rules for orderable products
   and free delivery. See how many pairs the bought together source has and
   recalculate it from the order history.
2. **Product page, cart page, cart drawer, order confirmation.** For each place choose
   the sources, the number of products and the heading. The cart page, the drawer and
   the confirmation can be switched off.

## Architecture

- **`Recommender`** picks products for a place from its sources, in order, and applies
  the shared rules.
- **`BoughtTogether`** keeps the product pairs in the `quince_crossselling_pair` table,
  filled from order history and updated on `actionOrderStatusPostUpdate`.
- **Hooks**: `displayQuinceCrossSelling` (product page, placed by Quince Engine),
  `displayQuinceCartSuggest` and `displayCrossSellingShoppingCart` (cart page),
  `displayQuinceCartUpsell` (cart drawer) and `displayOrderConfirmation2`.
- **Symfony controller and Twig** for the settings screen, inside the Quince Engine
  shell.
- **Settings** are stored in PrestaShop configuration under the `QUINCE_CROSS_` prefix.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_crossselling.git
cd quince_crossselling
composer install
```

The folder must be called `quince_crossselling`, the same as the module. A clone of
this repository already has that name.

To build an installable archive, zip the `quince_crossselling/` folder **with its
`vendor/` directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
