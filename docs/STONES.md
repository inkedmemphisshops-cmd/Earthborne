# Loose Stone Import Contract

Future stone batches must use `data/stone-import-template.csv` and follow these rules.

## Identity and deduplication

- `sku` is the exact Stuller SKU and the permanent unique key.
- An existing SKU is updated, never duplicated.
- Each one-of-a-kind loose stone has stock quantity `1`.
- A sold or unavailable stone becomes out of stock; it is not silently deleted.

## Catalog rules

- Natural stones only.
- No pearls.
- One-of-a-kind loose stones must be over 1 carat unless manually approved.
- Category: `One-of-a-Kind Stones`, with `Loose Gemstones` as the broader catalog path.
- Price is Stuller cost multiplied by `2.0` unless a row explicitly carries an approved retail price.

## Images

- `image_urls` accepts every available Stuller image, separated by `|`.
- The first URL is the featured image; remaining URLs become gallery images.
- Images must be copied into the WordPress media library rather than hotlinked.
- Duplicate image URLs are ignored.

## Required validation before import

The importer must reject rows with a missing SKU, missing name, non-natural stone, pearl material, invalid cost, or a duplicate SKU inside the same batch. Rejected rows belong in an error report and must not partially create a product.

## Availability refresh

The scheduled sync updates exact-SKU availability, quantity, source cost, Earthborne retail price, last-check timestamp, and a human-readable status note. Product descriptions, categories, and manually edited merchandising copy remain untouched.
