<?php

declare(strict_types=1);

namespace App\Modules\Search\Database\Seeders;

use App\Modules\Search\Services\TextNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Starter English ⇄ Bangla pairs (and common spelling variants). Staff can add, change and remove pairs in the admin panel. */
class SearchSynonymSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string}> */
    private const PAIRS = [
        ['mobile', 'মোবাইল'], ['phone', 'ফোন'], ['mobile', 'phone'], ['headphone', 'হেডফোন'], ['earphone', 'ইয়ারফোন'], ['laptop', 'ল্যাপটপ'], ['charger', 'চার্জার'],
        ['shoes', 'জুতা'], ['shoe', 'জুতা'], ['shoes', 'shoe'], ['sandal', 'স্যান্ডেল'], ['sandal', 'চপ্পল'], ['chappal', 'চপ্পল'], ['sneakers', 'স্নিকার্স'], ['loafer', 'লোফার'], ['boots', 'বুট'],
        ['saree', 'শাড়ি'], ['sari', 'saree'], ['panjabi', 'পাঞ্জাবি'], ['punjabi', 'panjabi'], ['kurti', 'কুর্তি'], ['shirt', 'শার্ট'], ['tshirt', 'টি শার্ট'], ['t shirt', 'tshirt'],
        ['polo', 'পোলো'], ['hoodie', 'হুডি'], ['lungi', 'লুঙ্গি'], ['scarf', 'স্কার্ফ'], ['jamdani', 'জামদানি'], ['cap', 'ক্যাপ'], ['belt', 'বেল্ট'],
        ['bag', 'ব্যাগ'], ['handbag', 'হ্যান্ডব্যাগ'], ['backpack', 'ব্যাকপ্যাক'], ['wallet', 'মানিব্যাগ'], ['watch', 'ঘড়ি'], ['sunglasses', 'সানগ্লাস'], ['necklace', 'হার'], ['earrings', 'দুল'],
        ['towel', 'তোয়ালে'], ['pillow', 'বালিশ'], ['bedsheet', 'চাদর'], ['curtain', 'পর্দা'], ['lamp', 'ল্যাম্প'], ['candle', 'মোমবাতি'], ['bottle', 'বোতল'], ['basket', 'ঝুড়ি'],
        ['mat', 'পাটি'], ['rug', 'পাপোশ'], ['kantha', 'কাঁথা'], ['notebook', 'নোটবুক'], ['ebook', 'ই বুক'], ['tailoring', 'দর্জি'], ['alteration', 'কাঁটছাঁট'],
    ];

    public function run(): void
    {
        $now = now('UTC');
        foreach (self::PAIRS as [$a, $b]) {
            $term = TextNormalizer::text($a);
            $synonym = TextNormalizer::text($b);
            if ($term !== '' && $synonym !== '' && $term !== $synonym) {
                DB::table('search_synonyms')->insertOrIgnore(['term' => $term, 'synonym' => $synonym, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
