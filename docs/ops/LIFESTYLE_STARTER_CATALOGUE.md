# Starter catalogue for a Bangladeshi lifestyle shop

One command adds the structure a lifestyle shop needs before its first product, in **English and Bangla**. It adds **structure only**: no products, prices, stock or customers.

```
php artisan db:seed --class='Database\Seeders\BangladeshLifestyleSeeder' --force
```

(On the Namecheap server: over SSH, in `~/velmora`, after the first-time set-up and the first administrator.) The same command is safe to run again and on a live shop (see "Safety").

## What it adds

| Part | Count | Notes |
|---|---|---|
| **Categories** | 137 in 12 areas, up to 3 levels | Women (Saree with Jamdani, Tangail, Katan/Benarasi, Silk, Cotton, Monipuri...; Three-piece; Kurti; Borka, Abaya and Hijab; ...), Men (Panjabi, Fotua, Shirts, Lungi and Pajama, ...), Kids and Baby, Footwear, Bags and Accessories (incl. Jewellery, Bangles/Churi), Beauty and Personal Care (incl. Attar, Agarbatti and Bakhoor, Mehendi), Home and Living (incl. Mosquito nets, Jaynamaz, Tasbih, Puja items), Handicrafts and Heritage (Nakshi Kantha, Shital Pati, Jute and Cane, Terracotta, Brass, Handloom), Fitness and Sports (Cricket, Football, Badminton), Gifts and Occasions (Eid, Holud, Pohela Boishakh), Books and Stationery (Bangla books, musical instruments), Services and Digital (tailoring, mehendi appointments, gift cards). Each area has a short description (good for search engines). |
| **Attributes (variant options and specifications)** | 30, with 231 values | Options a buyer picks: Colour (24 colours with swatches), Size (XS to 4XL, Free size), Waist size, Shoe size (EU), Kids' age, Bangle size, Bed size, Volume, Pack size (incl. dozen). Specifications shown and filtered: Fabric (Cotton, Khadi, Muslin, Katan, Endi, Georgette, ...), Material, Pattern (Nakshi, Block print, Batik, ...), Fit, Sleeve, Occasion (Eid, Wedding, Gaye Holud, Puja, Pohela Boishakh, ...), For (Women, Men, ...), Origin (Handmade, Handloom, Made in Bangladesh), Traditional craft, Care, Skin type and concern, Hair type, Fragrance family, Alcohol-free, Certification (BSTI, Halal, ...), Jewellery material, Warranty, Language, Format, Age group. |
| **Brands** | 37 | **3 active**: Velmora, Velmora Basics, Local Artisans. **34 inactive**: well-known Bangladeshi and international lifestyle brand *names*, listed by name only (no logo, no claim of any partnership). |
| **Tags** | 29 | New arrival, Bestseller, Eid special, Ramadan, Pohela Boishakh, Puja, Wedding, Gaye Holud, Winter/Summer/Monsoon, Handloom, Handmade, Made in Bangladesh, Eco-friendly, Organic, Gift idea, Couple wear, School season, Book fair, Victory Day, ... |
| **Collections** | 12, **inactive** | New arrivals, Best sellers, Eid, Pohela Boishakh, Wedding and Holud, Winter, Monsoon, Handloom heritage, Gift guide, Under ৳999, Back to school, Victory Day. |
| **Storefront menus** | header and footer | Header: eight areas as drop-downs (each with "All ..." and its main sub-categories), Gifts and Occasions, New arrivals. Footer: Shop (All products, Gifts, Fitness, Books, Services) and Customer care (Help centre, Contact us, My account, My orders). Links use each language's own address, so Bangla links open Bangla pages. |
| **Search spellings** | 93 | Panjabi / punjabi / পাঞ্জাবি, saree / sari / শাড়ি, borka / burqa / বোরকা, attar / ittar / আতর, mehendi / henna / মেহেদি, chappal / chapal / চপ্পল, and more, so people find things however they type them. |

"Features" in the sense of product specifications are the **specification attributes** above. (The staff "Features" screen is a different thing: on/off switches such as cart reminders.)

## Safety

* **Adds only what is missing.** Every item is found by its slug or code first. Nothing that exists is renamed, changed or deleted: a category you renamed keeps your name; an existing "Colour" attribute keeps its name and values and only gains the missing colours.
* **Menus are made only if that menu is empty**, so a menu your staff built is never touched.
* **Brand names are not endorsements.** The inactive brands are a convenience list. **Switch on only the brands you are authorised to sell** (staff screen *Brands*), and delete the rest. Never show a brand's logo or name as a partner unless you have that right.
* **Collections stay hidden** until you add products to them (an empty page helps nobody). Switch each on in *Collections* when it has products.
* The categories are all active, so the storefront shows them (empty) from the start. **Hide the categories you will not use** (edit, untick active) before opening the shop, or the header will lead customers to empty pages.
* Run it **before** adding products. It works with the demo data too (the 3 matching demo categories are reused).

## After running it

1. Open *Categories* and hide or rename what does not fit your shop. The Bangla names are a good starting point; have a native speaker of your customers' region read them.
2. Open *Brands* and switch on only your own authorised brands.
3. In *Attributes*, remove values you will never use (a long Colour list is fine, but unused sizes clutter the filters).
4. Add products. Choose the category, brand, tags, and the specifications that apply; variant options (colour, size) create the variants.
5. Switch collections on as they fill.
6. Check the storefront in English and Bangla.

## What this does not do

No products, images, prices, stock, shipping rates (see the delivery screens), payment settings, policy text or banners. Those need your real business information.
