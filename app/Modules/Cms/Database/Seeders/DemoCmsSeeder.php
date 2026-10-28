<?php

declare(strict_types=1);

namespace App\Modules\Cms\Database\Seeders;

use App\Modules\Cms\Actions\SaveBanner;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starter pages and banners for development. The page text is generic placeholder copy, NOT legal text: privacy,
 * terms and refund policies must be written and reviewed by the owner before launch. Development only; re-runnable.
 */
class DemoCmsSeeder extends Seeder
{
    /** @var array<string, array{en: array{0: string, 1: string}, bn: array{0: string, 1: string}}> */
    private const PAGES = [
        'about' => [
            'en' => ['About us', '<p>We bring everyday essentials, handmade crafts and useful services to your door across Bangladesh.</p><p>Every product is described in English and Bangla so you can shop in the language you prefer.</p>'],
            'bn' => ['আমাদের সম্পর্কে', '<p>আমরা বাংলাদেশজুড়ে আপনার দোরগোড়ায় প্রতিদিনের প্রয়োজনীয় পণ্য, হস্তশিল্প ও দরকারি সেবা পৌঁছে দিই।</p><p>প্রতিটি পণ্যের বিবরণ ইংরেজি ও বাংলায় দেওয়া আছে, যাতে আপনি পছন্দের ভাষায় কেনাকাটা করতে পারেন।</p>'],
        ],
        'delivery' => [
            'en' => ['Delivery information', '<h2>Delivery times</h2><p>Dhaka: 1–2 working days. Other districts: 2–5 working days.</p><h2>Cash on delivery</h2><p>Pay the courier when your order arrives.</p>'],
            'bn' => ['ডেলিভারি তথ্য', '<h2>ডেলিভারির সময়</h2><p>ঢাকা: ১–২ কর্মদিবস। অন্যান্য জেলা: ২–৫ কর্মদিবস।</p><h2>ক্যাশ অন ডেলিভারি</h2><p>অর্ডার পৌঁছালে কুরিয়ারকে টাকা দিন।</p>'],
        ],
        'faq' => [
            'en' => ['Frequently asked questions', '<h2>How do I track my order?</h2><p>Sign in and open My orders.</p><h2>Can I change my delivery address?</h2><p>Contact us before the order is shipped.</p><h2>Which payment methods do you accept?</h2><p>Cash on delivery, bKash and cards.</p>'],
            'bn' => ['সচরাচর জিজ্ঞাসা', '<h2>আমার অর্ডার কীভাবে ট্র্যাক করব?</h2><p>সাইন ইন করে “আমার অর্ডার” খুলুন।</p><h2>ডেলিভারির ঠিকানা বদলানো যাবে?</h2><p>অর্ডার পাঠানোর আগে আমাদের জানান।</p><h2>কোন কোন পেমেন্ট পদ্ধতি চলে?</h2><p>ক্যাশ অন ডেলিভারি, বিকাশ ও কার্ড।</p>'],
        ],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo CMS content is for development only.');
        }

        $actor = Actor::system('demo-seeder');

        foreach (self::PAGES as $slug => $t) {
            if (DB::table('cms_page_translations')->where('locale', 'en')->where('slug', $slug)->exists()) {
                continue;
            }
            $page = app(SavePage::class)->handle($actor, null, [
                'en' => ['title' => $t['en'][0], 'slug' => $slug, 'body' => $t['en'][1]],
                'bn' => ['title' => $t['bn'][0], 'body' => $t['bn'][1]],
            ]);
            app(TransitionPage::class)->publish($actor, Page::query()->findOrFail($page->id));
        }

        if (DB::table('banners')->count() === 0) {
            foreach ([
                ['home_hero', 0, [14, 90, 200], ['en' => ['Everyday essentials, delivered', 'Handpicked for you', 'Shop now'], 'bn' => ['প্রতিদিনের প্রয়োজন, দোরগোড়ায়', 'আপনার জন্য বাছাই করা', 'কিনুন']]],
                ['home_hero', 1, [20, 120, 90], ['en' => ['Eid specials', 'Panjabi, saree and gifts', 'See the collection'], 'bn' => ['ঈদ স্পেশাল', 'পাঞ্জাবি, শাড়ি ও উপহার', 'কালেকশন দেখুন']]],
            ] as [$placement, $order, $rgb, $text]) {
                $translations = [];
                foreach ($text as $locale => [$title, $subtitle, $cta]) {
                    $translations[$locale] = ['title' => $title, 'subtitle' => $subtitle, 'cta_label' => $cta, 'alt' => $title, 'image' => $this->gradient($rgb)];
                }
                app(SaveBanner::class)->handle($actor, null, ['placement' => $placement, 'link_url' => '/en', 'sort_order' => $order, 'is_active' => true, 'starts_local' => null, 'ends_local' => null], $translations);
            }
        }
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb  a plain brand-coloured gradient: placeholder art, no text baked in */
    private function gradient(array $rgb): string
    {
        $im = imagecreatetruecolor(1800, 600);
        for ($x = 0; $x < 1800; $x += 6) {
            $k = $x / 1800;
            imagefilledrectangle($im, $x, 0, $x + 5, 600, (int) imagecolorallocate(
                $im,
                max(0, min(255, (int) ($rgb[0] + (255 - $rgb[0]) * $k * 0.5))),
                max(0, min(255, (int) ($rgb[1] + (255 - $rgb[1]) * $k * 0.5))),
                max(0, min(255, (int) ($rgb[2] + (255 - $rgb[2]) * $k * 0.4))),
            ));
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
