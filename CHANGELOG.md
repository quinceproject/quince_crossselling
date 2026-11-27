# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] - 2026-11-27

### Fixed

- The product page in the back office stopped with an error 500 when a spare parts or
  set items list held a reference made of digits only (for example `123456`). PHP turns
  such an array key into a number, and the list field accepts only text. References now
  stay text everywhere the lists are read.

## [0.3.1] - 2026-11-16

### Added

- Two product fields under **Related products** in the product form: **Spare parts
  list** and **Set items list**. Products are picked with the same search as related
  products. The field keeps product references (SKU), in the order picked. A reference
  the shop does not have yet is kept and listed under the field.
- The lists are open to the Admin API as
  `extraProperties.quince_crossselling.spare_parts` and `set_items`: references
  separated by commas, read and written on `/admin-api/products/{productId}`.
- Four new suggestion sources: spare parts, products this one is a spare part for,
  set items and sets this product is in. They are off by default.
- Hook `displayQuinceProductList`, used by the product page carousels of Quince
  Engine 0.9.0.

### Changed

- The title of the product page section is set in **Quince Engine → Product page →
  Carousels**, like the titles of the other carousels. The title saved here before is
  carried over there as the default.
- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.2.4] - 2026-11-15

### Fixed

- Recommendations no longer show products that are only in categories the
  customer's group cannot see, or in inactive categories.

## [0.2.3] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could open and save the module's
  settings. The screens now belong to the module's own menu tab
  (`AdminQuinceCrossSelling`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.2.2] - 2026-11-10

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.
- Hook titles no longer use long dashes (new installations).

## [0.2.1] - 2026-11-10

First public release.

### Added

- Product suggestions in four places: under the product, on the cart page, in the
  cart drawer and under the order confirmation.
- Products picked, in order, from those bought together in past orders, the product
  accessories, the same category and the best sellers, with the sources chosen per
  place.
- "Bought together" pairs counted from the order history on install and kept up to
  date as orders are paid or cancelled, with a manual recalculation.
- Shared rules: only products that can be ordered, and products that close the gap to
  free delivery first.
- Number of products and heading per place, the heading in every language.
- Polish translation.
