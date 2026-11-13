<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

/**
 * The category tree of a Bangladeshi lifestyle shop: 12 areas, two or three levels. Each node is [slug, English, Bangla, children, [description EN, description BN]].
 * Slugs are the English slugs (stable: the menus and the seeder find categories by them). Edit freely in the admin afterwards; the seeder never overwrites a category that exists.
 */
final class LifestyleCategories
{
    /** @return list<array{0: string, 1: string, 2: string, 3?: list<mixed>, 4?: array{0: string, 1: string}}> */
    public static function tree(): array
    {
        return [
            ['women', 'Women', 'নারী', [
                ['women-saree', 'Saree', 'শাড়ি', [
                    ['jamdani-saree', 'Jamdani Saree', 'জামদানি শাড়ি'],
                    ['tangail-saree', 'Tangail Saree', 'টাঙ্গাইল শাড়ি'],
                    ['katan-benarasi-saree', 'Katan & Benarasi', 'কাতান ও বেনারসি'],
                    ['silk-saree', 'Silk Saree', 'সিল্ক শাড়ি'],
                    ['cotton-saree', 'Cotton Saree', 'সুতি শাড়ি'],
                    ['monipuri-saree', 'Monipuri Saree', 'মণিপুরি শাড়ি'],
                    ['georgette-chiffon-saree', 'Georgette & Chiffon', 'জর্জেট ও শিফন'],
                    ['block-batik-saree', 'Block & Batik Saree', 'ব্লক ও বাটিক শাড়ি'],
                ]],
                ['women-three-piece', 'Three-piece & Salwar Kameez', 'থ্রি-পিস ও সালোয়ার কামিজ'],
                ['women-kurti', 'Kurti & Tunics', 'কুর্তি ও টিউনিক'],
                ['women-modest-wear', 'Borka, Abaya & Hijab', 'বোরকা, আবায়া ও হিজাব', [
                    ['borka', 'Borka', 'বোরকা'],
                    ['abaya', 'Abaya', 'আবায়া'],
                    ['hijab-scarf', 'Hijab & Scarf', 'হিজাব ও স্কার্ফ'],
                ]],
                ['women-occasion-wear', 'Lehenga & Occasion Wear', 'লেহেঙ্গা ও অনুষ্ঠানের পোশাক'],
                ['women-western-wear', 'Western Wear', 'ওয়েস্টার্ন পোশাক'],
                ['women-loungewear', 'Loungewear & Innerwear', 'লাউঞ্জওয়্যার ও অন্তর্বাস'],
                ['women-winter-wear', 'Winter Wear', 'শীতের পোশাক'],
            ], ['Sarees, three-piece, kurti, borka and more: everyday and festive clothing for women, from Bangladeshi handloom to modern fashion.', 'শাড়ি, থ্রি-পিস, কুর্তি, বোরকা ও আরও অনেক কিছু: বাংলাদেশি তাঁত থেকে আধুনিক ফ্যাশন, নারীদের দৈনন্দিন ও উৎসবের পোশাক।']],
            ['men', 'Men', 'পুরুষ', [
                ['men-panjabi', 'Panjabi', 'পাঞ্জাবি', [
                    ['casual-panjabi', 'Casual Panjabi', 'ক্যাজুয়াল পাঞ্জাবি'],
                    ['festive-panjabi', 'Festive & Wedding Panjabi', 'উৎসব ও বিয়ের পাঞ্জাবি'],
                    ['panjabi-set', 'Panjabi Sets', 'পাঞ্জাবি সেট'],
                ]],
                ['men-fotua-kurta', 'Fotua & Kurta', 'ফতুয়া ও কুর্তা'],
                ['men-shirts', 'Shirts', 'শার্ট', [
                    ['formal-shirts', 'Formal Shirts', 'ফরমাল শার্ট'],
                    ['casual-shirts', 'Casual Shirts', 'ক্যাজুয়াল শার্ট'],
                ]],
                ['men-tshirts-polo', 'T-shirts & Polo', 'টি-শার্ট ও পোলো'],
                ['men-pants-jeans', 'Pants, Jeans & Trousers', 'প্যান্ট, জিন্স ও ট্রাউজার'],
                ['men-lungi-pajama', 'Lungi & Pajama', 'লুঙ্গি ও পাজামা'],
                ['men-suits-blazers', 'Suits & Blazers', 'স্যুট ও ব্লেজার'],
                ['men-winter-wear', 'Winter Wear', 'শীতের পোশাক'],
                ['men-innerwear', 'Innerwear & Sleepwear', 'অন্তর্বাস ও নাইটওয়্যার'],
            ], ['Panjabi, shirts, T-shirts, trousers and traditional wear for men, for work, weekends and every festival.', 'কাজ, ছুটির দিন ও সব উৎসবের জন্য পুরুষদের পাঞ্জাবি, শার্ট, টি-শার্ট, ট্রাউজার ও ঐতিহ্যবাহী পোশাক।']],
            ['kids', 'Kids & Baby', 'শিশু ও বেবি', [
                ['kids-boys', 'Boys\' Clothing', 'ছেলেদের পোশাক'],
                ['kids-girls', 'Girls\' Clothing', 'মেয়েদের পোশাক'],
                ['kids-festive', 'Festive & Eid Wear', 'উৎসব ও ঈদের পোশাক'],
                ['baby-essentials', 'Baby Clothing & Essentials', 'নবজাতক ও বেবি এসেনশিয়ালস'],
                ['kids-toys', 'Toys & Games', 'খেলনা ও গেমস'],
                ['kids-school', 'School Supplies', 'স্কুল সামগ্রী'],
            ], ['Soft, safe and cheerful: clothing, toys and school supplies for babies and children.', 'নরম, নিরাপদ ও আনন্দময়: শিশু ও বাচ্চাদের পোশাক, খেলনা ও স্কুল সামগ্রী।']],
            ['footwear', 'Footwear', 'জুতা', [
                ['sneakers', 'Sneakers', 'স্নিকার্স'],
                ['formal-shoes-loafers', 'Formal Shoes & Loafers', 'ফরমাল জুতা ও লোফার'],
                ['sandals-chappals', 'Sandals & Chappals', 'স্যান্ডেল ও চপ্পল'],
                ['heels-flats', 'Heels & Flats', 'হিল ও ফ্ল্যাট'],
                ['boots-rain-shoes', 'Boots & Rain Shoes', 'বুট ও রেইন শু'],
                ['sports-shoes', 'Sports Shoes', 'স্পোর্টস শু'],
                ['slippers-flip-flops', 'Slippers & Flip-flops', 'স্লিপার ও ফ্লিপ-ফ্লপ'],
                ['kids-footwear', 'Kids\' Footwear', 'শিশুদের জুতা'],
            ], ['Leather, canvas and rubber: shoes, sandals and chappals for men, women and children.', 'চামড়া, ক্যানভাস ও রাবার: পুরুষ, নারী ও শিশুদের জুতা, স্যান্ডেল ও চপ্পল।']],
            ['bags-accessories', 'Bags & Accessories', 'ব্যাগ ও অ্যাক্সেসরিজ', [
                ['handbags', 'Handbags', 'হ্যান্ডব্যাগ'],
                ['backpacks', 'Backpacks', 'ব্যাকপ্যাক'],
                ['wallets-purses', 'Wallets & Purses', 'মানিব্যাগ ও পার্স'],
                ['belts', 'Belts', 'বেল্ট'],
                ['watches', 'Watches', 'ঘড়ি'],
                ['jewellery', 'Jewellery', 'গহনা', [
                    ['imitation-jewellery', 'Imitation & Gold-plated', 'ইমিটেশন ও গোল্ড-প্লেটেড'],
                    ['silver-jewellery', 'Silver Jewellery', 'রুপার গহনা'],
                    ['handmade-jewellery', 'Terracotta & Handmade', 'টেরাকোটা ও হাতে তৈরি গহনা'],
                    ['bangles-churi', 'Bangles (Churi)', 'চুড়ি'],
                ]],
                ['eyewear', 'Sunglasses & Eyewear', 'সানগ্লাস ও চশমা'],
                ['caps-scarves-shawls', 'Caps, Mufflers & Shawls', 'ক্যাপ, মাফলার ও শাল'],
                ['umbrellas', 'Umbrellas', 'ছাতা'],
                ['travel-luggage', 'Travel & Luggage', 'ভ্রমণ ও লাগেজ'],
            ], ['Handbags, wallets, watches, jewellery and everyday accessories, from leather craft to terracotta.', 'হ্যান্ডব্যাগ, মানিব্যাগ, ঘড়ি, গহনা ও দৈনন্দিন অ্যাক্সেসরিজ: চামড়ার কাজ থেকে টেরাকোটা পর্যন্ত।']],
            ['beauty-personal-care', 'Beauty & Personal Care', 'সৌন্দর্য ও ব্যক্তিগত যত্ন', [
                ['skincare', 'Skincare', 'ত্বকের যত্ন'],
                ['makeup', 'Makeup', 'মেকআপ'],
                ['hair-care', 'Hair Care', 'চুলের যত্ন'],
                ['fragrance-attar', 'Attar, Perfume & Fragrance', 'আতর, পারফিউম ও সুগন্ধি', [
                    ['attar', 'Attar', 'আতর'],
                    ['perfume-body-spray', 'Perfume & Body Spray', 'পারফিউম ও বডি স্প্রে'],
                    ['agarbatti-bakhoor', 'Agarbatti & Bakhoor', 'আগরবাতি ও বাখুর'],
                ]],
                ['mens-grooming', 'Men\'s Grooming', 'পুরুষদের গ্রুমিং'],
                ['bath-body', 'Bath & Body', 'গোসল ও বডি কেয়ার'],
                ['mehendi-nail', 'Mehendi & Nail Care', 'মেহেদি ও নেইল কেয়ার'],
                ['herbal-natural-care', 'Herbal & Natural Care', 'হারবাল ও প্রাকৃতিক যত্ন'],
            ], ['Skincare, makeup, hair care, attar and grooming for every skin type and every day.', 'প্রতিটি ত্বকের ধরন ও প্রতিদিনের জন্য স্কিনকেয়ার, মেকআপ, চুলের যত্ন, আতর ও গ্রুমিং।']],
            ['home-living', 'Home & Living', 'ঘর ও জীবনযাপন', [
                ['home-decor', 'Home Decor', 'গৃহসজ্জা'],
                ['bedding-linen', 'Bedding & Linen', 'বিছানা ও লিনেন', [
                    ['bedsheets', 'Bedsheets', 'বেডশিট'],
                    ['pillows-cushions', 'Pillows & Cushions', 'বালিশ ও কুশন'],
                    ['blankets-kantha', 'Blankets & Kantha', 'কম্বল ও কাঁথা'],
                    ['mosquito-nets', 'Mosquito Nets', 'মশারি'],
                ]],
                ['kitchen-dining', 'Kitchen & Dining', 'রান্নাঘর ও ডাইনিং', [
                    ['cookware', 'Cookware', 'রান্নার সামগ্রী'],
                    ['dinnerware', 'Dinnerware', 'ডিনারওয়্যার'],
                    ['kitchen-storage', 'Storage & Containers', 'স্টোরেজ ও কন্টেইনার'],
                    ['bottles-flasks', 'Bottles & Flasks', 'বোতল ও ফ্লাস্ক'],
                ]],
                ['bath-linen', 'Bath Linen', 'বাথ লিনেন'],
                ['furniture', 'Furniture', 'আসবাবপত্র'],
                ['lighting', 'Lighting', 'আলো'],
                ['rugs-mats-curtains', 'Rugs, Mats & Curtains', 'কার্পেট, মাদুর ও পর্দা'],
                ['cleaning-organisers', 'Cleaning & Organisers', 'পরিষ্কার ও গোছানোর সামগ্রী'],
                ['garden-planters', 'Garden & Planters', 'বাগান ও টব'],
                ['prayer-devotional', 'Prayer & Devotional', 'ইবাদত ও পূজার সামগ্রী', [
                    ['prayer-mats', 'Prayer Mats (Jaynamaz)', 'জায়নামাজ'],
                    ['tasbih-rehal', 'Tasbih & Rehal', 'তসবিহ ও রেহাল'],
                    ['puja-items', 'Puja Items', 'পূজার সামগ্রী'],
                ]],
            ], ['Bedding, kitchenware, decor and furniture to make a home comfortable, with a Bangladeshi touch.', 'বিছানা, রান্নাঘরের সামগ্রী, গৃহসজ্জা ও আসবাবপত্র: বাংলাদেশি ছোঁয়ায় ঘরকে আরামদায়ক করুন।']],
            ['handicrafts-heritage', 'Handicrafts & Heritage', 'হস্তশিল্প ও ঐতিহ্য', [
                ['nakshi-kantha', 'Nakshi Kantha', 'নকশি কাঁথা'],
                ['jute-cane-crafts', 'Jute & Cane Crafts', 'পাট ও বেতের কাজ'],
                ['shital-pati', 'Shital Pati', 'শীতল পাটি'],
                ['terracotta-pottery', 'Terracotta & Pottery', 'টেরাকোটা ও মৃৎশিল্প'],
                ['brass-metal-craft', 'Brass & Metal Craft', 'পিতল ও ধাতব শিল্প'],
                ['wood-bamboo-craft', 'Wooden & Bamboo Craft', 'কাঠ ও বাঁশের কাজ'],
                ['handloom-fabrics', 'Handloom Fabrics', 'তাঁতের কাপড়'],
                ['folk-art', 'Folk Art & Paintings', 'লোকশিল্প ও চিত্রকর্ম'],
            ], ['Traditional Bangladeshi crafts made by artisans: nakshi kantha, shital pati, jute, terracotta and handloom.', 'কারিগরদের হাতে তৈরি ঐতিহ্যবাহী বাংলাদেশি হস্তশিল্প: নকশি কাঁথা, শীতল পাটি, পাট, টেরাকোটা ও তাঁত।']],
            ['fitness-sports', 'Fitness & Sports', 'ফিটনেস ও খেলাধুলা', [
                ['sportswear', 'Sportswear', 'স্পোর্টসওয়্যার'],
                ['cricket', 'Cricket', 'ক্রিকেট'],
                ['football', 'Football', 'ফুটবল'],
                ['badminton-racquet', 'Badminton & Racquet Sports', 'ব্যাডমিন্টন ও র‍্যাকেট'],
                ['yoga-fitness', 'Yoga & Fitness', 'যোগব্যায়াম ও ফিটনেস'],
                ['cycling', 'Cycling', 'সাইক্লিং'],
                ['outdoor-camping', 'Outdoor & Camping', 'আউটডোর ও ক্যাম্পিং'],
            ], ['Cricket, football, badminton and fitness gear and sportswear for an active life.', 'সক্রিয় জীবনের জন্য ক্রিকেট, ফুটবল, ব্যাডমিন্টন ও ফিটনেস সামগ্রী এবং স্পোর্টসওয়্যার।']],
            ['gifts-occasions', 'Gifts & Occasions', 'উপহার ও উৎসব', [
                ['eid-gifts', 'Eid Gifts', 'ঈদ উপহার'],
                ['wedding-holud', 'Wedding & Gaye Holud', 'বিয়ে ও গায়ে হলুদ'],
                ['pohela-boishakh', 'Pohela Boishakh', 'পহেলা বৈশাখ'],
                ['birthday-anniversary', 'Birthday & Anniversary', 'জন্মদিন ও বার্ষিকী'],
                ['corporate-gifts', 'Corporate Gifts', 'কর্পোরেট উপহার'],
                ['gift-sets-hampers', 'Gift Sets & Hampers', 'গিফট সেট ও হ্যাম্পার'],
            ], ['Thoughtful gifts for Eid, weddings, Pohela Boishakh, birthdays and every celebration.', 'ঈদ, বিয়ে, পহেলা বৈশাখ, জন্মদিনসহ প্রতিটি উদযাপনের জন্য চিন্তাশীল উপহার।']],
            ['books-stationery', 'Books & Stationery', 'বই ও স্টেশনারি', [
                ['bangla-books', 'Bangla Books', 'বাংলা বই'],
                ['english-books', 'English Books', 'ইংরেজি বই'],
                ['childrens-books', 'Children\'s Books', 'শিশুদের বই'],
                ['stationery-office', 'Stationery & Office', 'স্টেশনারি ও অফিস সামগ্রী'],
                ['art-craft-supplies', 'Art & Craft Supplies', 'শিল্প ও কারুশিল্প সামগ্রী'],
                ['musical-instruments', 'Musical Instruments', 'বাদ্যযন্ত্র'],
            ], ['Bangla and English books, stationery, art supplies and traditional musical instruments.', 'বাংলা ও ইংরেজি বই, স্টেশনারি, শিল্প সামগ্রী ও ঐতিহ্যবাহী বাদ্যযন্ত্র।']],
            ['digital-services', 'Services & Digital', 'সেবা ও ডিজিটাল', [
                ['tailoring-alteration', 'Tailoring & Alteration', 'দর্জির কাজ ও কাঁটছাঁট'],
                ['beauty-appointments', 'Beauty & Mehendi Appointments', 'বিউটি ও মেহেদি অ্যাপয়েন্টমেন্ট'],
                ['gift-wrapping', 'Gift Wrapping & Personalisation', 'গিফট র‍্যাপিং ও ব্যক্তিগতকরণ'],
                ['gift-cards', 'Gift Cards', 'গিফট কার্ড'],
                ['ebooks-guides', 'E-books & Style Guides', 'ই-বুক ও স্টাইল গাইড'],
            ], ['Book a tailor or a mehendi artist, add gift wrapping, or buy gift cards and e-books.', 'দর্জি বা মেহেদি শিল্পী বুক করুন, গিফট র‍্যাপিং যোগ করুন, অথবা গিফট কার্ড ও ই-বুক কিনুন।']],
        ];
    }
}
