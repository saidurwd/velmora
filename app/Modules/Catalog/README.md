
* Phase 9, slice 4: `PurchasableVariant` now carries `productId`.
* Phase 10, slice 2b: `Contracts/ProductGroupings` gives the Reporting module each variant's product, primary category and brand (names in a language, falling back to the default).
* Phase 10, slice 3c-2: `Contracts/HomeCollections` lists the active collections the home page may feature (for the staff form) and says whether one exists; the home page renders the blocks Cms hands over (`Cms\Contracts\HomeLayout`) and leaves out a block with nothing to show, falling back to the built-in layout.
* Phase 10, slice 5a: `Search/SkuSearch` (products by the start of a SKU).
