<?php

use App\Actions\Setup\IssueSetupToken;
use App\I18n\Locales;
use App\I18n\Translations;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

describe('which languages exist', function () {
    it('offers the seven languages, English first', function () {
        expect(array_keys(Locales::available()))->toBe(['en', 'fr', 'de', 'es', 'ru', 'uk', 'tr'])
            ->and(Locales::available()['uk'])->toBe('Українська');
    });

    it('falls back to English for anything unknown', function () {
        expect(Locales::resolve('fr'))->toBe('fr')
            ->and(Locales::resolve('xx'))->toBe('en')
            ->and(Locales::resolve(null))->toBe('en');
    });

    it('picks the best language the browser asks for among those offered', function (string $header, ?string $expected) {
        $request = request()->duplicate();
        $request->headers->set('Accept-Language', $header);

        expect(Locales::fromRequest($request))->toBe($expected);
    })->with([
        ['fr-CH, fr;q=0.9, en;q=0.8', 'fr'],
        ['pt-BR, es;q=0.8, en;q=0.5', 'es'],
        ['de;q=0.4, tr;q=0.9', 'tr'],
        ['uk-UA,uk;q=0.9,ru;q=0.5', 'uk'],
        ['ja, zh;q=0.9', null],
        ['*', null],
        ['', null],
        ['fr;q=0', null],
    ]);
});

describe('which language a page is in', function () {
    it('uses the language of the signed-in person', function () {
        $this->actingAs(User::factory()->create(['locale' => 'de']))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    });

    it('uses English for a person with no language, and for one that is no longer offered', function () {
        $this->actingAs(User::factory()->create(['locale' => null]))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));

        $this->actingAs(User::factory()->create(['locale' => 'xx']))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    });

    it('uses the browser language before sign-in, and English when it is not offered', function () {
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'tr'));
        $this->withHeader('Accept-Language', 'ja')->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    });

    it('remembers a language picked before sign-in for the session', function () {
        $this->get('/login?lang=ru')->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
        $this->get('/login?lang=nope')->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
    });

    it('does not let ?lang change a signed-in person', function () {
        $this->actingAs(User::factory()->create(['locale' => 'fr']))->get('/dashboard?lang=de')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
    });

    it('shares the list of languages with every page', function () {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('locales.0', ['code' => 'en', 'name' => 'English'])
            ->has('locales', 7));
    });
});

describe('changing your own language', function () {
    it('saves it for the person only', function () {
        $me = User::factory()->create();
        $other = User::factory()->create(['locale' => 'es']);

        $this->actingAs($me)->put('/settings/language', ['locale' => 'fr'])->assertRedirect();

        expect($me->fresh()->locale)->toBe('fr')->and($other->fresh()->locale)->toBe('es');
    });

    it('refuses a language that does not exist', function () {
        $me = User::factory()->create();

        $this->actingAs($me)->put('/settings/language', ['locale' => 'xx'])->assertSessionHasErrors('locale');
        expect($me->fresh()->locale)->toBeNull();
    });

    it('needs a signed-in person', function () {
        $this->put('/settings/language', ['locale' => 'fr'])->assertRedirect('/login');
    });
});

describe('the language of new people', function () {
    it('is chosen on the first setup page and saved for the first admin', function () {
        $token = app(IssueSetupToken::class)();

        $this->post("/setup/{$token}", ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a-long-enough-password-1', 'password_confirmation' => 'a-long-enough-password-1', 'locale' => 'uk'])
            ->assertRedirect();

        expect(User::query()->where('email', 'ana@example.com')->value('locale'))->toBe('uk');
    });

    it('is English on setup when none is chosen and the browser offers none', function () {
        $token = app(IssueSetupToken::class)();

        $this->withHeader('Accept-Language', 'ja')->post("/setup/{$token}", ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a-long-enough-password-1', 'password_confirmation' => 'a-long-enough-password-1']);

        expect(User::query()->where('email', 'ana@example.com')->value('locale'))->toBe('en');
    });

    it('is chosen when an admin creates a user, and defaults to their own', function () {
        $admin = User::factory()->create(['locale' => 'de', 'is_super' => true]);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('/users', ['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'a-long-enough-password-1', 'password_confirmation' => 'a-long-enough-password-1', 'locale' => 'tr'])->assertSessionHasNoErrors();
        $this->post('/users', ['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a-long-enough-password-1', 'password_confirmation' => 'a-long-enough-password-1'])->assertSessionHasNoErrors();

        expect(User::query()->where('email', 'bo@example.com')->value('locale'))->toBe('tr')
            ->and(User::query()->where('email', 'cy@example.com')->value('locale'))->toBe('de');
    });
});

describe('the sentences', function () {
    it('are sent once for the language, and nothing for English', function () {
        // A folder of its own, so the real language files are never touched.
        $dir = sys_get_temp_dir().'/commypanel-lang-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/fr.json", json_encode(['Save' => 'Enregistrer', 'Empty' => '']));
        app()->useLangPath($dir);

        try {
            expect(Translations::for('fr'))->toBe(['Save' => 'Enregistrer'])
                ->and(Translations::for('en'))->toBe([])
                ->and(Translations::for('xx'))->toBe([]);

            $this->actingAs(User::factory()->create(['locale' => 'fr']))->get('/dashboard')
                ->assertInertia(fn (Assert $page) => $page->where('translations.Save', 'Enregistrer'));
        } finally {
            File::deleteDirectory($dir);
            app()->useLangPath(base_path('lang'));
        }
    });
});
