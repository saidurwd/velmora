<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

/**
 * Brands, tags and collections. Rows are [slug, English, Bangla].
 *
 * BRANDS: the shop's own labels are active. The well-known brands below are listed by NAME ONLY (no logo, no claim of any partnership) and are INACTIVE: the owner switches
 * on only the brands the shop is authorised to sell. Delete any that will never be used.
 * COLLECTIONS are inactive until products are added to them (an empty page helps nobody).
 */
final class LifestyleTaxonomy
{
    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function ownBrands(): array
    {
        return [
            ['velmora', 'Velmora', 'ভেলমোরা'],
            ['velmora-basics', 'Velmora Basics', 'ভেলমোরা বেসিকস'],
            ['local-artisans', 'Local Artisans', 'স্থানীয় কারিগর'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function catalogueBrands(): array
    {
        return [
            ['aarong', 'Aarong', 'আড়ং'],
            ['yellow', 'Yellow', 'ইয়েলো'],
            ['ecstasy', 'Ecstasy', 'ইকস্ট্যাসি'],
            ['cats-eye', 'Cats Eye', 'ক্যাটস আই'],
            ['richman', 'Richman', 'রিচম্যান'],
            ['sailor', 'Sailor', 'সেইলর'],
            ['dorjibari', 'Dorjibari', 'দর্জিবাড়ি'],
            ['anjans', 'Anjan\'s', 'অঞ্জনস'],
            ['kay-kraft', 'Kay Kraft', 'কে ক্র্যাফট'],
            ['bibiana', 'Bibiana', 'বিবিয়ানা'],
            ['rang-bangladesh', 'Rang Bangladesh', 'রং বাংলাদেশ'],
            ['le-reve', 'Le Reve', 'লা রিভ'],
            ['easy', 'Easy', 'ইজি'],
            ['artisan', 'Artisan', 'আর্টিজান'],
            ['apex', 'Apex', 'এপেক্স'],
            ['bata', 'Bata', 'বাটা'],
            ['keya', 'Keya', 'কেয়া'],
            ['meril', 'Meril', 'মেরিল'],
            ['hatil', 'Hatil', 'হাতিল'],
            ['otobi', 'Otobi', 'অটোবি'],
            ['regal', 'Regal', 'রিগ্যাল'],
            ['partex', 'Partex', 'পার্টেক্স'],
            ['rfl', 'RFL', 'আরএফএল'],
            ['pran', 'PRAN', 'প্রাণ'],
            ['nike', 'Nike', 'নাইকি'],
            ['adidas', 'Adidas', 'অ্যাডিডাস'],
            ['puma', 'Puma', 'পিউমা'],
            ['levis', 'Levi\'s', 'লিভাইস'],
            ['casio', 'Casio', 'ক্যাসিও'],
            ['fossil', 'Fossil', 'ফসিল'],
            ['nivea', 'Nivea', 'নিভিয়া'],
            ['himalaya', 'Himalaya', 'হিমালয়া'],
            ['loreal', 'L\'Oréal', 'লরিয়াল'],
            ['garnier', 'Garnier', 'গার্নিয়ার'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function tags(): array
    {
        return [
            ['new-arrival', 'New arrival', 'নতুন'],
            ['bestseller', 'Bestseller', 'সেরা বিক্রি'],
            ['eid-special', 'Eid special', 'ঈদ বিশেষ'],
            ['ramadan', 'Ramadan', 'রমজান'],
            ['pohela-boishakh', 'Pohela Boishakh', 'পহেলা বৈশাখ'],
            ['puja', 'Puja', 'পূজা'],
            ['wedding', 'Wedding', 'বিয়ে'],
            ['gaye-holud', 'Gaye Holud', 'গায়ে হলুদ'],
            ['winter-collection', 'Winter collection', 'শীতের কালেকশন'],
            ['summer-collection', 'Summer collection', 'গ্রীষ্মের কালেকশন'],
            ['monsoon-essentials', 'Monsoon essentials', 'বর্ষার প্রয়োজনীয়'],
            ['handloom', 'Handloom', 'তাঁতের তৈরি'],
            ['handmade', 'Handmade', 'হাতে তৈরি'],
            ['made-in-bangladesh', 'Made in Bangladesh', 'বাংলাদেশে তৈরি'],
            ['eco-friendly', 'Eco-friendly', 'পরিবেশবান্ধব'],
            ['organic', 'Organic', 'অর্গানিক'],
            ['premium', 'Premium', 'প্রিমিয়াম'],
            ['budget-friendly', 'Budget-friendly', 'সাশ্রয়ী'],
            ['gift-idea', 'Gift idea', 'উপহারের আইডিয়া'],
            ['couple-wear', 'Couple wear', 'কাপলের পোশাক'],
            ['family-set', 'Family set', 'ফ্যামিলি সেট'],
            ['limited-edition', 'Limited edition', 'সীমিত সংস্করণ'],
            ['office-wear', 'Office wear', 'অফিসের পোশাক'],
            ['party-wear', 'Party wear', 'পার্টির পোশাক'],
            ['comfort-fit', 'Comfort fit', 'আরামদায়ক ফিট'],
            ['school-season', 'School season', 'স্কুল মৌসুম'],
            ['boi-mela', 'Book fair', 'বইমেলা'],
            ['independence-month', 'Independence month', 'স্বাধীনতার মাস'],
            ['victory-day', 'Victory Day', 'বিজয় দিবস'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function collections(): array
    {
        return [
            ['new-arrivals', 'New arrivals', 'নতুন পণ্য'],
            ['best-sellers', 'Best sellers', 'সেরা বিক্রিত'],
            ['eid-collection', 'Eid collection', 'ঈদ কালেকশন'],
            ['pohela-boishakh-collection', 'Pohela Boishakh collection', 'পহেলা বৈশাখ কালেকশন'],
            ['wedding-holud-collection', 'Wedding & Holud edit', 'বিয়ে ও গায়ে হলুদ'],
            ['winter-essentials', 'Winter essentials', 'শীতের প্রয়োজনীয়'],
            ['monsoon-ready', 'Monsoon ready', 'বর্ষার প্রস্তুতি'],
            ['handloom-heritage', 'Handloom heritage', 'তাঁত ঐতিহ্য'],
            ['gift-guide', 'Gift guide', 'উপহার গাইড'],
            ['under-999', 'Under ৳999', '৯৯৯ টাকার নিচে'],
            ['back-to-school', 'Back to school', 'স্কুলে ফেরা'],
            ['victory-day-collection', 'Victory Day collection', 'বিজয় দিবস কালেকশন'],
        ];
    }
}
