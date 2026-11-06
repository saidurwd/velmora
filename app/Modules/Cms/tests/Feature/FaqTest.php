<?php

declare(strict_types=1);

use App\Modules\Cms\Actions\SaveFaqEntry;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->editor = catalogStaff(['cms.view', 'cms.edit']);
    $this->publisher = catalogStaff(['cms.view', 'cms.edit', 'cms.publish']);
    $this->viewer = catalogStaff(['cms.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->save = fn (array $over = [], ?User $as = null, ?string $entry = null) => $this->actingAs($as ?? $this->publisher, 'admin')->{$entry === null ? 'post' : 'put'}($entry === null ? '/admin/cms/faq' : "/admin/cms/faq/{$entry}", $over + [
        'category' => 'delivery', 'sort' => 1, 'published_present' => '1', 'published' => '1',
        'texts' => ['en' => ['question' => 'How long does delivery take?', 'answer' => "Two to four days inside Dhaka.\nThree to six elsewhere."], 'bn' => ['question' => 'ডেলিভারি কত দিনে হয়?', 'answer' => 'ঢাকার ভেতরে দুই থেকে চার দিন।']],
    ]);
    $this->entry = fn (string $q = 'How long does delivery take?') => DB::table('faq_entry_translations')->where('question', $q)->where('locale', 'en')->value('faq_entry_id');
});

describe('the help page', function () {
    it('is open to everyone, and shows only published questions grouped by topic', function () {
        ($this->save)();
        ($this->save)(['category' => 'payment', 'published' => null, 'texts' => ['en' => ['question' => 'Hidden question?', 'answer' => 'Hidden answer.']]]);
        ($this->save)(['category' => 'payment', 'texts' => ['en' => ['question' => 'Can I pay by bKash?', 'answer' => 'Yes, through our payment partner.']]]);

        $html = $this->get('/en/help')->assertOk()->assertSee('Help centre')->assertSee('How long does delivery take?')->assertSee('Can I pay by bKash?')->assertDontSee('Hidden question?')->getContent();
        expect(strpos($html, 'Delivery'))->toBeLessThan(strpos($html, 'Payment'));
        $this->get('/en/help')->assertSee('Contact us');
    });

    it('answers in the visitor\'s language and falls back to the default when a question has no translation', function () {
        ($this->save)();
        ($this->save)(['texts' => ['en' => ['question' => 'English only question?', 'answer' => 'English only answer.']]]);

        $this->get('/bn/help')->assertOk()->assertSee('ডেলিভারি কত দিনে হয়?')->assertSee('English only question?')->assertSee('সহায়তা কেন্দ্র');
        $this->get('/en/help')->assertSee('How long does delivery take?')->assertDontSee('ডেলিভারি কত দিনে হয়?');
    });

    it('searches the questions and the answers, finds nothing politely, and takes no markup', function () {
        ($this->save)();
        ($this->save)(['category' => 'payment', 'texts' => ['en' => ['question' => 'Can I pay by bKash?', 'answer' => 'Yes, through our payment partner.']]]);

        $this->get('/en/help?q=bkash')->assertSee('Can I pay by bKash?')->assertDontSee('How long does delivery take?');
        $this->get('/en/help?q=Dhaka')->assertSee('How long does delivery take?')->assertDontSee('bKash');
        $this->get('/en/help?q=zzzz')->assertOk()->assertSee('Nothing matched');
        $html = $this->get('/en/help?'.http_build_query(['q' => '<script>alert(1)</script>']))->getContent();
        expect($html)->not->toContain('<script>alert(1)')->and($html)->toContain('&lt;script&gt;');
    });

    it('shows an answer as plain text with its line breaks, never as a page', function () {
        ($this->save)(['texts' => ['en' => ['question' => 'Is it safe?', 'answer' => '<script>alert(1)</script> <b>yes</b>']]]);

        $html = $this->get('/en/help')->getContent();
        expect($html)->not->toContain('<script>alert(1)')->and($html)->not->toContain('<b>yes</b>')->and($html)->toContain('&lt;b&gt;yes&lt;/b&gt;');
    });

    it('says so when there is nothing yet, and has the same words in both languages', function () {
        $this->get('/en/help')->assertOk()->assertSee('There is nothing here yet.');
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Cms/resources/lang/en/faq.php'));
        $bn = $keys(require app_path('Modules/Cms/resources/lang/bn/faq.php'));
        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });
});

describe('the questions', function () {
    it('are for staff who may see or edit content, and nobody else', function () {
        $this->get('/admin/cms/faq')->assertRedirect();
        $this->actingAs($this->nobody, 'admin')->get('/admin/cms/faq')->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get('/admin/cms/faq')->assertOk();
        $this->actingAs($this->viewer, 'admin')->get('/admin/cms/faq/create')->assertForbidden();
        ($this->save)([], $this->viewer)->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->delete('/admin/cms/faq/'.str_repeat('0', 26))->assertForbidden();

        expect(DB::table('faq_entries')->count())->toBe(0);
    });

    it('start hidden when written by someone who cannot publish, and they cannot show or hide them', function () {
        ($this->save)([], $this->editor)->assertRedirect('/admin/cms/faq');
        $id = ($this->entry)();
        expect((bool) DB::table('faq_entries')->where('id', $id)->value('is_published'))->toBeFalse();
        $this->get('/en/help')->assertDontSee('How long does delivery take?');

        $public = DB::table('faq_entries')->where('id', $id)->value('public_id');
        ($this->save)(['published' => '1'], $this->editor, $public);
        expect((bool) DB::table('faq_entries')->where('id', $id)->value('is_published'))->toBeFalse();

        ($this->save)(['published' => '1'], $this->publisher, $public);
        expect((bool) DB::table('faq_entries')->where('id', $id)->value('is_published'))->toBeTrue();
        ($this->save)(['published_present' => '1', 'published' => null], $this->publisher, $public);   // box unticked
        expect((bool) DB::table('faq_entries')->where('id', $id)->value('is_published'))->toBeFalse();
    });

    it('keeps the text of each language, refuses half a language and a missing English, and a topic not on the list', function () {
        ($this->save)();
        ($this->save)(['texts' => ['en' => ['question' => 'Q only', 'answer' => '']]])->assertSessionHasErrors('faq');
        ($this->save)(['texts' => ['bn' => ['question' => 'শুধু বাংলা', 'answer' => 'উত্তর']]])->assertSessionHasErrors('faq');
        ($this->save)(['category' => 'weather'])->assertSessionHasErrors('faq');
        ($this->save)(['texts' => ['en' => ['question' => str_repeat('q', 201), 'answer' => 'x']]])->assertSessionHasErrors();

        expect(DB::table('faq_entries')->count())->toBe(1)->and(DB::table('faq_entry_translations')->count())->toBe(2);
        expect(fn () => app(SaveFaqEntry::class)->handle(new Actor(ActorType::Staff, $this->publisher->id, null, 'S'), null, 'delivery', 0, true, true, []))->toThrow(CmsException::class);
    });

    it('can be edited, losing a language that is emptied, and deleted with its texts', function () {
        ($this->save)();
        $id = ($this->entry)();
        $public = DB::table('faq_entries')->where('id', $id)->value('public_id');

        ($this->save)(['texts' => ['en' => ['question' => 'How long does delivery take?', 'answer' => 'Updated answer.'], 'bn' => ['question' => '', 'answer' => '']]], null, $public)->assertRedirect('/admin/cms/faq');
        expect(DB::table('faq_entry_translations')->where('faq_entry_id', $id)->pluck('locale')->all())->toBe(['en']);
        $this->actingAs($this->publisher, 'admin')->get("/admin/cms/faq/{$public}/edit")->assertOk()->assertSee('Updated answer.');
        $this->actingAs($this->publisher, 'admin')->get('/admin/cms/faq/'.str_repeat('0', 26).'/edit')->assertNotFound();

        $this->actingAs($this->publisher, 'admin')->delete("/admin/cms/faq/{$public}")->assertRedirect('/admin/cms/faq');
        expect(DB::table('faq_entries')->count())->toBe(0)->and(DB::table('faq_entry_translations')->count())->toBe(0)->and(DB::table('audit_events')->where('action', 'cms.faq_deleted')->count())->toBe(1);
    });

    it('is shown to staff in English and in Bangla with no missing words', function () {
        ($this->save)();
        $this->publisher->forceFill(['locale' => 'bn'])->save();
        $public = DB::table('faq_entries')->value('public_id');

        foreach (['/admin/cms/faq', '/admin/cms/faq/create', "/admin/cms/faq/{$public}/edit"] as $page) {
            expect($this->actingAs($this->publisher, 'admin')->get($page)->assertOk()->getContent())->not->toContain('cms::faq');
        }
        $this->actingAs($this->publisher, 'admin')->get('/admin/cms/faq')->assertSee('সহায়তা কেন্দ্রের প্রশ্ন');
    });
});
