<?php

use Aladler\LaravelPennantSessionAndDbDriver\SessionAndDatabaseDriver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Contracts\FeatureScopeable;
use Laravel\Pennant\Events\AllFeaturesPurged;
use Laravel\Pennant\Events\DynamicallyRegisteringFeatureClass;
use Laravel\Pennant\Events\FeatureDeleted;
use Laravel\Pennant\Events\FeatureResolved;
use Laravel\Pennant\Events\FeaturesPurged;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;
use Laravel\Pennant\Events\UnknownFeatureResolved;
use Laravel\Pennant\Feature;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

use function Orchestra\Testbench\workbench_path;
use function Pest\Laravel\be;

beforeEach(function () {
    Feature::extend('session_and_database', function () {
        return new SessionAndDatabaseDriver(
            app()['db']->connection(),
            app()['events'],
            config(),
            []
        );
    });
});

it('syncs features from database to session when the scope is the current auth user', function () {
    be($user = new User(['id' => 1]));

    Feature::activate('foo');

    expect(session('features'))->toBe(['foo' => true])
        ->and(Feature::for($user)->active('foo'))->toBeTrue()
        ->and(DB::table('features')->get())->toHaveCount(1);
});

it('does not sync features from database to session when the scope is not the current auth user', function () {
    $user = new User(['id' => 1]);
    Feature::for($user)->activate('foo');
    expect(Feature::for($user)->active('foo'))->toBeTrue()
        ->and(session('features'))->toBeNull()
        ->and(DB::table('features')->get())->toHaveCount(1);

    be($user);
    $user2 = new User(['id' => 2]);
    Feature::for($user2)->activate('foo');
    expect(Feature::for($user2)->active('foo'))->toBeTrue()
        ->and(session('features'))->toBeNull()
        ->and(DB::table('features')->get())->toHaveCount(2);
});

it('does not sync features from database to session when the scope is not a UserThatHasPreRegisterFeatures model', function () {
    Feature::for('jimmy')->activate('foo');
    expect(Feature::for('jimmy')->active('foo'))->toBeTrue()
        ->and(session('features'))->toBeNull()
        ->and(DB::table('features')->get())->toHaveCount(1);
});

it('uses the session when no scope is passed and no user is auth', function () {
    Feature::activate('foo');

    expect(Feature::active('foo'))->toBeTrue()
        ->and(session('features'))->toBe(['foo' => true])
        ->and(DB::table('features')->get())->toBeEmpty();
});

it('uses session and database when user is auth and no scope is passed', function () {
    be(new User(['id' => 1]));
    Feature::activate('foo');

    expect(Feature::active('foo'))->toBeTrue()
        ->and(session('features'))->toBe(['foo' => true]);

    $row = DB::table('features')->first();
    expect($row->name)->toBe('foo')
        ->and($row->scope)->toBe(User::class.'|1')
        ->and($row->value)->toBe('true')
        ->and(DB::table('features')->get())->toHaveCount(1);
});

it('sync to the database after login and feature check', function () {
    Feature::activate('foo');

    expect(Feature::active('foo'))->toBeTrue()
        ->and(session('features'))->toBe(['foo' => true])
        ->and(DB::table('features')->get())->toBeEmpty();

    be(new User(['id' => 1]));
    $is_active = Feature::active('foo');
    $row = DB::table('features')->first();

    expect($is_active)->toBeTrue()
        ->and(session('features'))->toBe(['foo' => true])
        ->and($row->name)->toBe('foo')
        ->and($row->scope)->toBe(User::class.'|1')
        ->and($row->value)->toBe('true')
        ->and(DB::table('features')->get())->toHaveCount(1);

});

it('defaults to false for unknown values', function () {
    $result = Feature::active('foo');
    expect($result)->toBeFalse()
        ->and(session('features'))->toBeNull()
        ->and(DB::table('features')->get())->toBeEmpty();
});

it('dispatches events on unknown feature checks', function () {
    Event::fake([UnknownFeatureResolved::class]);

    Feature::active('foo');

    Event::assertDispatchedTimes(UnknownFeatureResolved::class, 1);
    Event::assertDispatched(function (UnknownFeatureResolved $event) {
        $this->assertSame('foo', $event->feature);
        $this->assertNull($event->scope);

        return true;
    });

    expect(session('features'))->toBeNull()
        ->and(DB::table('features')->get())->toBeEmpty();
});

it('can register default boolean values', function () {
    Feature::define('true', fn () => true);
    Feature::define('false', fn () => false);

    $true = Feature::active('true');
    $false = Feature::active('false');

    $this->assertTrue($true);
    $this->assertFalse($false);

    expect(session('features'))->toBe(
        [
            'true' => true,
            'false' => false,
        ]
    )->and(DB::table('features')->get())->toBeEmpty();
});

it('can register complex values', function () {
    Feature::define('config', fn () => [
        'color' => 'red',
        'default' => 'api',
    ]);

    $active = Feature::active('config');
    $value = Feature::value('config');

    expect($active)->toBeTrue()
        ->and($value)->toBe(
            [
                'color' => 'red',
                'default' => 'api',
            ]
        )
        ->and(DB::table('features')->get())->toBeEmpty();

    Feature::for('tim')->activate('new-api', 'foo foo');

    $active = Feature::for('tim')->active('new-api');
    $value = Feature::for('tim')->value('new-api');
    $row = DB::table('features')->first();

    expect($active)->toBeTrue()
        ->and($value)->toBe('foo foo')
        ->and(session('features'))->toBe(
            [
                'config' => [
                    'color' => 'red',
                    'default' => 'api',
                ],
            ]
        )
        ->and($row->name)->toBe('new-api')
        ->and($row->scope)->toBe('tim')
        ->and($row->value)->toBe('"foo foo"')
        ->and(DB::table('features')->get())->toHaveCount(1);
});

it('caches state after resolving', function () {
    $called = 0;
    Feature::define('foo', function () use (&$called) {
        $called++;

        return true;
    });

    expect($called)->toBe(0);

    Feature::active('foo');
    expect($called)->toBe(1);

    Feature::active('foo');
    expect($called)->toBe(1)
        ->and(DB::table('features')->get())->toBeEmpty();

});

it('considers active all non-false registered values', function () {
    Feature::define('true', fn () => true);
    Feature::define('false', fn () => false);
    Feature::define('one', fn () => 1);
    Feature::define('zero', fn () => 0);
    Feature::define('null', fn () => null);
    Feature::define('empty-string', fn () => '');

    $this->assertTrue(Feature::active('true'));
    $this->assertFalse(Feature::active('false'));
    $this->assertTrue(Feature::active('one'));
    $this->assertTrue(Feature::active('zero'));
    $this->assertTrue(Feature::active('null'));
    $this->assertTrue(Feature::active('empty-string'));

    $this->assertFalse(Feature::inactive('true'));
    $this->assertTrue(Feature::inactive('false'));
    $this->assertFalse(Feature::inactive('one'));
    $this->assertFalse(Feature::inactive('zero'));
    $this->assertFalse(Feature::inactive('null'));
    $this->assertFalse(Feature::inactive('empty-string'));

    expect(DB::table('features')->get())->toBeEmpty();
});

it('can programmatically activate and deactivate features', function () {
    Feature::activate('foo');
    $this->assertTrue(Feature::active('foo'));

    Feature::deactivate('foo');
    $this->assertFalse(Feature::active('foo'));

    Feature::activate('foo');
    $this->assertTrue(Feature::active('foo'));

    expect(DB::table('features')->get())->toBeEmpty();
});

it('dispatches events when checking known features', function () {
    Event::fake([FeatureResolved::class]);
    Feature::define('foo', fn () => true);

    Feature::active('foo');
    Feature::active('foo');

    Event::assertDispatchedTimes(FeatureResolved::class, 1);
    Event::assertDispatched(function (FeatureResolved $event) {
        return $event->feature === 'foo' && $event->scope === null;
    });
});

it('can activate and deactivate several features at once', function () {
    Feature::activate(['foo', 'bar']);

    $this->assertTrue(Feature::active('foo'));
    $this->assertTrue(Feature::active('bar'));
    $this->assertFalse(Feature::active('baz'));

    Feature::deactivate(['foo', 'bar']);

    $this->assertFalse(Feature::active('foo'));
    $this->assertFalse(Feature::active('bar'));
    $this->assertFalse(Feature::active('bar'));

    Feature::activate(['bar', 'baz']);

    $this->assertFalse(Feature::active('foo'));
    $this->assertTrue(Feature::active('bar'));
    $this->assertTrue(Feature::active('bar'));

    expect(DB::table('features')->get())->toBeEmpty();
});

it('can check if multiple features are active at once', function () {
    Feature::activate(['foo', 'bar']);

    $this->assertTrue(Feature::allAreActive(['foo']));
    $this->assertTrue(Feature::allAreActive(['foo', 'bar']));
    $this->assertFalse(Feature::allAreActive(['foo', 'bar', 'baz']));

    Feature::deactivate('baz');

    $this->assertTrue(Feature::allAreActive(['foo']));
    $this->assertTrue(Feature::allAreActive(['foo', 'bar']));
    $this->assertFalse(Feature::allAreActive(['foo', 'bar', 'baz']));

    expect(DB::table('features')->get())->toBeEmpty();
});

it('can scope features', function () {
    $active = new User(['id' => 1]);
    $inactive = new User(['id' => 2]);
    $captured = [];

    Feature::define('foo', function ($scope) use (&$captured) {
        $captured[] = $scope;

        return $scope?->id === 1;
    });

    $this->assertFalse(Feature::active('foo'));
    $this->assertTrue(Feature::for($active)->active('foo'));
    $this->assertFalse(Feature::for($inactive)->active('foo'));
    $this->assertSame([null, $active, $inactive], $captured);

    expect(DB::table('features')->get())->toHaveCount(2);
});

it('can activate and deactivate features with scope', function () {
    $first = new User(['id' => 1]);
    $second = new User(['id' => 2]);

    Feature::for($first)->activate('foo');

    $this->assertFalse(Feature::active('foo'));
    $this->assertTrue(Feature::for($first)->active('foo'));
    $this->assertFalse(Feature::for($second)->active('foo'));

    expect(DB::table('features')->get())->toHaveCount(1);
});

it('can activate and deactivate features for multiple scopes at once', function () {
    $first = new User(['id' => 1]);
    $second = new User(['id' => 2]);
    $third = new User(['id' => 3]);

    Feature::for([$first, $second])->activate('foo');

    $this->assertFalse(Feature::active('foo'));
    $this->assertTrue(Feature::for($first)->active('foo'));
    $this->assertTrue(Feature::for($second)->active('foo'));
    $this->assertFalse(Feature::for($third)->active('foo'));

    expect(DB::table('features')->get())->toHaveCount(2);
});

it('can activate and deactivate multiple features for multiple scope at once', function () {
    $first = new User(['id' => 1]);
    $second = new User(['id' => 2]);
    $third = new User(['id' => 3]);

    Feature::for([$first, $second])->activate(['foo', 'bar']);

    $this->assertFalse(Feature::active('foo'));
    $this->assertTrue(Feature::for($first)->active('foo'));
    $this->assertTrue(Feature::for($second)->active('foo'));
    $this->assertFalse(Feature::for($third)->active('foo'));

    $this->assertFalse(Feature::active('bar'));
    $this->assertTrue(Feature::for($first)->active('bar'));
    $this->assertTrue(Feature::for($second)->active('bar'));
    $this->assertFalse(Feature::for($third)->active('bar'));

    expect(DB::table('features')->get())->toHaveCount(4);

    Feature::for([$first, $second])->deactivate(['foo', 'bar']);

    $this->assertFalse(Feature::active('foo'));
    $this->assertFalse(Feature::for($first)->active('foo'));
    $this->assertFalse(Feature::for($second)->active('foo'));
    $this->assertFalse(Feature::for($third)->active('foo'));

    $this->assertFalse(Feature::active('bar'));
    $this->assertFalse(Feature::for($first)->active('bar'));
    $this->assertFalse(Feature::for($second)->active('bar'));
    $this->assertFalse(Feature::for($third)->active('bar'));

    expect(DB::table('features')->get())->toHaveCount(4);
});

it('can check multiple features for multiple scopes at once', function () {
    $first = new User(['id' => 1]);
    $second = new User(['id' => 2]);
    $third = new User(['id' => 3]);

    Feature::for([$first, $second])->activate(['foo', 'bar']);

    $this->assertFalse(Feature::allAreActive(['foo', 'bar']));
    $this->assertTrue(Feature::for($first)->allAreActive(['foo', 'bar']));
    $this->assertTrue(Feature::for($second)->allAreActive(['foo', 'bar']));
    $this->assertFalse(Feature::for($third)->allAreActive(['foo', 'bar']));

    $this->assertTrue(Feature::for([$first, $second])->allAreActive(['foo', 'bar']));
    $this->assertFalse(Feature::for([$second, $third])->allAreActive(['foo', 'bar']));
    $this->assertFalse(Feature::for([$first, $second, $third])->allAreActive(['foo', 'bar']));

    expect(DB::table('features')->get())->toHaveCount(4);
});

it('treats null as global', function () {
    Feature::activate('foo');

    expect(Feature::for(null)->active('foo'))->toBeTrue()
        ->and(DB::table('features')->get())->toBeEmpty();
});

it('sees null and empty string as different things', function () {
    Feature::activate('foo');

    $this->assertFalse(Feature::for('')->active('foo'));
    $this->assertTrue(Feature::for(null)->active('foo'));
    $this->assertTrue(Feature::active('foo'));

    Feature::for('')->activate('bar');

    $this->assertTrue(Feature::for('')->active('bar'));
    $this->assertFalse(Feature::for(null)->active('bar'));
    $this->assertFalse(Feature::active('bar'));

    expect(DB::table('features')->get())->toHaveCount(1);
});

test('scopes can be strings like email addresses', function () {
    Feature::for('tim@laravel.com')->activate('foo');

    $this->assertFalse(Feature::for('james@laravel.com')->active('foo'));
    $this->assertTrue(Feature::for('tim@laravel.com')->active('foo'));

    expect(DB::table('features')->get())->toHaveCount(1);
});

it('can handle feature scopeable objects', function () {
    $scopeable = fn () => new class extends User implements FeatureScopeable
    {
        public function toFeatureIdentifier($driver): mixed
        {
            return 'tim@laravel.com';
        }
    };

    Feature::for($scopeable())->activate('foo');

    $this->assertFalse(Feature::for('james@laravel.com')->active('foo'));
    $this->assertTrue(Feature::for('tim@laravel.com')->active('foo'));
    $this->assertTrue(Feature::for($scopeable())->active('foo'));

    expect(DB::table('features')->get())->toHaveCount(1);
});

it('serializes eloquent models', function () {
    Feature::for(UserFactory::new()->create())->activate('foo');

    $scope = DB::table('features')->value('scope');

    $this->assertStringContainsString('Workbench\App\Models\User|1', $scope);
});

it('can load feature state into memory', function () {
    $called = ['foo' => 0, 'bar' => 0];
    Feature::define('foo', function () use (&$called) {
        $called['foo']++;

        return true;
    });
    Feature::define('bar', function () use (&$called) {
        $called['bar']++;

        return true;
    });

    $this->assertSame(0, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::load('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::load(['foo']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::load('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::load(['bar']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::load(['foo', 'bar']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::allAreActive(['foo', 'bar']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);
});

it('can load scoped feature state into memory', function () {
    $called = ['foo' => 0, 'bar' => 0];
    Feature::define('foo', function ($scope) use (&$called) {
        $called['foo']++;

        return true;
    });
    Feature::define('bar', function () use (&$called) {
        $called['bar']++;

        return true;
    });

    $this->assertSame(0, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load(['foo']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load(['bar']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::for('loaded')->active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::for('noloaded')->active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(2, $called['bar']);

    Feature::getAll([
        'foo' => [1, 2, 3],
        'bar' => [2],
    ]);
    $this->assertSame(4, $called['foo']);
    $this->assertSame(3, $called['bar']);

    Feature::for([1, 2, 3])->active('foo');
    Feature::for([2])->active('bar');
    $this->assertSame(4, $called['foo']);
    $this->assertSame(3, $called['bar']);
});

it('can load against scope', function () {
    $called = ['foo' => 0, 'bar' => 0];
    Feature::define('foo', function ($scope) use (&$called) {
        $called['foo']++;

        return true;
    });
    Feature::define('bar', function () use (&$called) {
        $called['bar']++;

        return true;
    });

    $this->assertSame(0, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load(['foo']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::for('loaded')->load('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::for('loaded')->active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::for('noloaded')->active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(2, $called['bar']);

    Feature::for([1, 2, 3])->load(['foo']);
    Feature::for(2)->load(['bar']);
    $this->assertSame(4, $called['foo']);
    $this->assertSame(3, $called['bar']);

    Feature::for([1, 2, 3])->active('foo');
    Feature::for([2])->active('bar');
    $this->assertSame(4, $called['foo']);
    $this->assertSame(3, $called['bar']);
});

it('can load missing feature state into memory', function () {
    $called = ['foo' => 0, 'bar' => 0];
    Feature::define('foo', function () use (&$called) {
        $called['foo']++;

        return true;
    });
    Feature::define('bar', function () use (&$called) {
        $called['bar']++;

        return true;
    });

    $this->assertSame(0, $called['foo']);

    Feature::loadMissing('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::loadMissing('foo');
    $this->assertSame(0, $called['bar']);

    Feature::active('foo');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(0, $called['bar']);

    Feature::active('bar');
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::loadMissing(['bar']);
    $this->assertSame(1, $called['foo']);
    $this->assertSame(1, $called['bar']);

    Feature::getAllMissing([
        'foo' => [1, 2, 3],
        'bar' => [2],
    ]);
    $this->assertSame(4, $called['foo']);
    $this->assertSame(2, $called['bar']);

    Feature::for([1, 2, 3])->active('foo');
    Feature::for([2])->active('bar');
    $this->assertSame(4, $called['foo']);
    $this->assertSame(2, $called['bar']);
});

test('unknown features are no persisted when loading', function () {
    Event::fake([UnknownFeatureResolved::class]);
    Feature::for('Alain')->load(['foo', 'bar']);

    Event::assertDispatchedTimes(UnknownFeatureResolved::class, 2);
    expect(DB::table('features')->get())->toBeEmpty()
        ->and(session('features'))->toBeNull();
});

test('missing results are inserted on load', function () {
    Feature::define('foo', function () {
        return 1;
    });
    Feature::define('bar', function () {
        return 2;
    });

    Feature::for('taylor@laravel.com')->activate('foo', 99);
    Feature::for(['tim@laravel.com', 'jess@laravel.com', 'taylor@laravel.com'])->load(['foo', 'bar']);

    expect(DB::table('features')->get())->toHaveCount(6);
    $this->assertDatabaseHas('features', [
        'name' => 'foo',
        'scope' => 'tim@laravel.com',
        'value' => '1',
    ]);
    $this->assertDatabaseHas('features', [
        'name' => 'foo',
        'scope' => 'jess@laravel.com',
        'value' => '1',
    ]);
    $this->assertDatabaseHas('features', [
        'name' => 'foo',
        'scope' => 'taylor@laravel.com',
        'value' => '99',
    ]);
    $this->assertDatabaseHas('features', [
        'name' => 'bar',
        'scope' => 'tim@laravel.com',
        'value' => '2',
    ]);
    $this->assertDatabaseHas('features', [
        'name' => 'bar',
        'scope' => 'jess@laravel.com',
        'value' => '2',
    ]);
    $this->assertDatabaseHas('features', [
        'name' => 'bar',
        'scope' => 'taylor@laravel.com',
        'value' => '2',
    ]);
});

it('can retrieve registered features', function () {
    Feature::define('foo', fn () => true);
    Feature::define('bar', fn () => false);
    Feature::define('baz', fn () => false);

    $registered = Feature::defined();

    $this->assertSame(['foo', 'bar', 'baz'], $registered);
});

it('can get all features', function () {
    Feature::define('foo', fn () => true);
    Feature::define('bar', fn () => false);

    $all = Feature::all();

    $this->assertSame([
        'foo' => true,
        'bar' => false,
    ], $all);
});

it('can reevaluate feature state', function () {
    Feature::define('foo', fn () => false);
    $this->assertFalse(Feature::for('tim')->value('foo'));

    Feature::for('tim')->forget('foo');

    Feature::define('foo', fn () => true);
    $this->assertTrue(Feature::for('tim')->value('foo'));
});

it('can purge flags', function () {
    Feature::define('foo', true);
    Feature::define('bar', false);

    Feature::for('tim')->active('foo');
    Feature::for('taylor')->active('foo');
    Feature::for('taylor')->active('bar');

    $this->assertSame(3, DB::table('features')->count());

    Feature::purge('foo');

    $this->assertSame(1, DB::table('features')->count());

    Feature::purge('bar');

    $this->assertSame(0, DB::table('features')->count());
});

it('can purge multiple flags at once', function () {
    Feature::define('foo', true);
    Feature::define('bar', false);
    Feature::define('baz', false);

    Feature::for('tim')->active('foo');
    Feature::for('tim')->active('foo');
    Feature::for('taylor')->active('foo');
    Feature::for('taylor')->active('bar');
    Feature::for('taylor')->active('baz');

    $this->assertSame(4, DB::table('features')->count());

    Feature::purge(['foo', 'bar']);

    $this->assertSame(1, DB::table('features')->count());

    Feature::purge(['baz']);

    $this->assertSame(0, DB::table('features')->count());
});

test('retrieving values after purging', function () {
    Feature::define('foo', false);

    Feature::for('tim')->activate('foo');

    $this->assertTrue(Feature::for('tim')->active('foo'));
    $this->assertSame(1, DB::table('features')->count());

    Feature::purge('foo');

    $this->assertSame(0, DB::table('features')->count());

    $this->assertFalse(Feature::for('tim')->active('foo'));
});

it('can purge all feature flags', function () {
    Feature::define('foo', true);
    Feature::define('bar', false);

    Feature::for('tim')->active('foo');
    Feature::for('taylor')->active('foo');
    Feature::for('taylor')->active('bar');

    $this->assertSame(3, DB::table('features')->count());

    Feature::purge();

    $this->assertSame(0, DB::table('features')->count());
});

it('can customise default scope', function () {
    $scopes = [];
    Feature::define('foo', function ($scope) use (&$scopes) {
        $scopes[] = $scope;

        return true;
    });

    Feature::active('foo');

    $user = new User(['id' => 1]);
    Feature::for($user)->active('foo');

    Feature::resolveScopeUsing(fn () => 'bar');
    Feature::active('foo');

    $this->assertSame([
        null,
        $user,
        'bar',
    ], $scopes);
});

it('doesnt include default scope when null', function () {
    $scopes = [];
    Feature::define('foo', function ($scope) use (&$scopes) {
        $scopes[] = $scope;

        return true;
    });

    be(new User(['id' => 1]));

    Feature::resolveScopeUsing(fn () => null);
    Feature::active('foo');

    $this->assertSame([
        null,
    ], $scopes);
});

it('does not store unknown features', function () {
    Event::fake([UnknownFeatureResolved::class]);

    Feature::active('foo');
    Feature::active('foo');

    expect(DB::table('features')->get())->toBeEmpty();
});

it('can use unregistered class features', function () {
    Event::fake([DynamicallyRegisteringFeatureClass::class]);

    Feature::value(UnregisteredFeature::class);
    $value = Feature::value(UnregisteredFeature::class);
    $registered = Feature::defined();

    $this->assertSame('unregistered-value', $value);
    $this->assertSame([UnregisteredFeature::class], $registered);
    Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
    Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
        $this->assertSame($event->feature, UnregisteredFeature::class);

        return true;
    });
});

it('can use unregistered class features with resolve method', function () {
    Event::fake([DynamicallyRegisteringFeatureClass::class]);

    Feature::value(UnregisteredFeatureWithResolve::class);
    $value = Feature::value(UnregisteredFeatureWithResolve::class);
    $registered = Feature::defined();

    $this->assertSame('unregistered-value.resolve', $value);
    $this->assertSame([UnregisteredFeatureWithResolve::class], $registered);
    Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
    Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
        $this->assertSame($event->feature, UnregisteredFeatureWithResolve::class);

        return true;
    });
});

it('can use unregistered class features with name property', function () {
    Event::fake([DynamicallyRegisteringFeatureClass::class]);

    Feature::value(UnregisteredFeatureWithName::class);
    $value = Feature::value(UnregisteredFeatureWithName::class);
    $registered = Feature::defined();

    $this->assertSame('unregistered-value', $value);
    $this->assertSame(['feature-name'], $registered);
    Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
    Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
        $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

        return true;
    });
});

it('can delete unregistered class features with name property', function () {
    Event::fake([DynamicallyRegisteringFeatureClass::class]);

    Feature::value(UnregisteredFeatureWithName::class);
    expect(session('features'))->toBe(['feature-name' => 'unregistered-value']);

    Feature::for('Alain')->value(UnregisteredFeatureWithName::class);
    $this->assertSame(1, DB::table('features')->where('name', 'feature-name')->count());

    Feature::forgetDrivers();

    Feature::forget(UnregisteredFeatureWithName::class);
    expect(session('features'))->toBeEmpty();

    Feature::for('Alain')->forget(UnregisteredFeatureWithName::class);
    $this->assertSame(0, DB::table('features')->where('name', 'feature-name')->count());

    Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);
    Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
        $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

        return true;
    });
});

it('can activate unregistered class features with name property', function () {
    Event::fake([DynamicallyRegisteringFeatureClass::class]);

    Feature::activate(UnregisteredFeatureWithName::class, 'expected-value');
    expect(session('features'))->toBe(['feature-name' => 'expected-value']);

    Feature::for('Alain')->activate(UnregisteredFeatureWithName::class, 'expected-value');
    $this->assertSame(1, DB::table('features')->where('name', 'feature-name')->where('value', '"expected-value"')->count());

    Feature::forgetDrivers();

    Feature::forget(UnregisteredFeatureWithName::class);
    expect(session('features'))->toBeEmpty();

    Feature::for('Alain')->forget(UnregisteredFeatureWithName::class);
    $this->assertSame(0, DB::table('features')->where('name', 'feature-name')->where('value', '"expected-value"')->count());

    Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);
    Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
        $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

        return true;
    });
});

it('should forget session values if the current scope is the auth user', function () {
    be($user = new User(['id' => 1]));
    Feature::activate('foo', 'bar');

    expect(session('features'))->toBe(['foo' => 'bar'])
        ->and(Feature::for($user)->active('foo'))->toBeTrue()
        ->and(Feature::for($user)->value('foo'))->toBe('bar')
        ->and(DB::table('features')->get())->toHaveCount(1);

    Feature::for($user)->forget('foo');
    expect(session('features'))->toBeEmpty()
        ->and(Feature::for($user)->active('foo'))->toBeFalse()
        ->and(Feature::for($user)->value('foo'))->toBeFalse()
        ->and(DB::table('features')->get())->toBeEmpty();
});

it('should not forget session values if the current scope is not the auth user', function () {
    be($user = new User(['id' => 1]));
    Feature::activate('foo', 'bar');

    expect(session('features'))->toBe(['foo' => 'bar'])
        ->and(Feature::for($user)->active('foo'))->toBeTrue()
        ->and(Feature::for($user)->value('foo'))->toBe('bar')
        ->and(DB::table('features')->get())->toHaveCount(1);

    $user2 = new User(['id' => 2]);
    Feature::for($user2)->activate('foo', 'bar2');
    expect(session('features'))->toBe(['foo' => 'bar'])
        ->and(Feature::for($user2)->active('foo'))->toBeTrue()
        ->and(Feature::for($user2)->value('foo'))->toBe('bar2')
        ->and(DB::table('features')->get())->toHaveCount(2);

    Feature::for($user2)->forget('foo');
    expect(session('features'))->toBe(['foo' => 'bar'])
        ->and(Feature::for($user)->active('foo'))->toBeTrue()
        ->and(Feature::for($user)->value('foo'))->toBe('bar')
        ->and(DB::table('features')->get())->toHaveCount(1)
        ->and(Feature::for($user2)->active('foo'))->toBeFalse()
        ->and(Feature::for($user2)->value('foo'))->toBeFalse();
});

it('can conditionally execute code block for inactive feature', function () {
    $active = $inactive = null;

    Feature::when('foo',
        function () use (&$active) {
            $active = true;
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    $this->assertNull($active);
    $this->assertTrue($inactive);
});

it('can conditionally execute code block for active feature', function () {
    $active = $inactive = null;
    Feature::activate('foo');

    Feature::when('foo',
        function () use (&$active) {
            $active = true;
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    $this->assertTrue($active);
    $this->assertNull($inactive);
});

it('receives value for feature in conditional code execution', function () {
    $active = $inactive = null;
    Feature::activate('foo', ['hello' => 'world']);

    Feature::when('foo',
        function ($value) use (&$active) {
            $active = $value;
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    $this->assertSame(['hello' => 'world'], $active);
    $this->assertNull($inactive);
});

test('conditionally executing code respects scope', function () {
    $active = $inactive = null;
    Feature::for('tim')->activate('foo');

    Feature::when('foo',
        function () use (&$active) {
            $active = true;
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    $this->assertNull($active);
    $this->assertTrue($inactive);

    $active = $inactive = null;

    Feature::for('tim')->when('foo',
        function () use (&$active) {
            $active = true;
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    $this->assertTrue($active);
    $this->assertNull($inactive);
});

test('conditional closures receive current feature interaction', function () {
    $active = $inactive = null;
    Feature::for('tim')->activate('foo', ['hello' => 'tim']);

    Feature::for('tim')->when('foo',
        function ($value, $feature) {
            $feature->deactivate('foo');
        },
        function () use (&$inactive) {
            $inactive = true;
        },
    );

    Feature::flushCache();
    $this->assertFalse(Feature::for('tim')->active('foo'));
    $this->assertNull($inactive);
});

it('can set for all', function () {
    Feature::define('foo', fn () => false);

    Feature::for('tim')->activate('foo');
    Feature::for('taylor')->activate('foo');

    $this->assertTrue(Feature::for('tim')->value('foo'));
    $this->assertTrue(Feature::for('taylor')->value('foo'));
    $this->assertTrue(Feature::getDriver()->get('foo', 'tim'));
    $this->assertTrue(Feature::getDriver()->get('foo', 'taylor'));

    Feature::deactivateForEveryone('foo');

    $this->assertFalse(Feature::for('tim')->value('foo'));
    $this->assertFalse(Feature::for('taylor')->value('foo'));
    $this->assertFalse(Feature::getDriver()->get('foo', 'tim'));
    $this->assertFalse(Feature::getDriver()->get('foo', 'taylor'));

    Feature::activateForEveryone('foo');

    $this->assertTrue(Feature::for('tim')->value('foo'));
    $this->assertTrue(Feature::for('taylor')->value('foo'));
    $this->assertTrue(Feature::getDriver()->get('foo', 'tim'));
    $this->assertTrue(Feature::getDriver()->get('foo', 'taylor'));
});

it('can auto register feature classes', function () {
    Feature::define('marketing-design', 'marketing-design-value');
    Feature::discover('Workbench\\App\\Features', workbench_path('app/Features'));

    $all = Feature::all();

    $this->assertSame([
        'marketing-design' => 'marketing-design-value',
        'Workbench\\App\\Features\\NewApi' => 'new-api-value',
    ], $all);
});

it('handles multi-scope checks', function () {
    Feature::define('foo', false);

    $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    Feature::for('tim')->activate('foo');

    $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    Feature::for('taylor')->activate('foo');

    $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    Feature::for('tim')->activate('bar');

    $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
    $this->assertFalse($result);

    Feature::for('taylor')->activate('bar');

    $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
    $this->assertFalse($result);

    $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
    $this->assertTrue($result);

    $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
    $this->assertTrue($result);
});

test('bulk insert adds timestamps', function () {
    Feature::define('foo', true);

    Feature::for('Alain')->values(['foo']);
    $record = DB::table('features')->first();

    $this->assertNotNull($record->updated_at);
    $this->assertNotNull($record->created_at);
});

test('stores may be configured', function () {
    $this->app['config']->set('database.connections.foo_connection', $this->app['config']->get('database.connections.testing'));
    $this->app['config']->set('database.connections.bar_connection', $this->app['config']->get('database.connections.testing'));
    $this->app['config']->set('pennant.stores.foo', [
        'driver' => 'database',
        'connection' => 'foo_connection',
        'table' => 'foo_features',
    ]);
    $this->app['config']->set('pennant.stores.bar', [
        'driver' => 'database',
        'connection' => 'bar_connection',
        'table' => 'bar_features',
    ]);
    $connectionResolver = fn () => $this->newQuery()->connection->getName();
    $tableResolver = fn () => $this->newQuery()->from;

    $driver = Feature::store('foo')->getDriver();
    $this->assertSame('foo_connection', $connectionResolver->bindTo($driver, $driver)());
    $this->assertSame('foo_features', $tableResolver->bindTo($driver, $driver)());

    $driver = Feature::store('bar')->getDriver();
    $this->assertSame('bar_connection', $connectionResolver->bindTo($driver, $driver)());
    $this->assertSame('bar_features', $tableResolver->bindTo($driver, $driver)());
});

it('dispatches events when purging features', function () {
    Event::fake([FeaturesPurged::class]);

    Feature::define('foo', fn () => true);
    Feature::define('bar', fn () => true);

    Feature::purge(['foo', 'bar', 'baz']);

    Event::assertDispatchedTimes(FeaturesPurged::class, 1);
    Event::assertDispatched(function (FeaturesPurged $event) {
        return $event->features === ['foo', 'bar', 'baz'];
    });
});

it('dispatches events when purging all features', function () {
    Event::fake([AllFeaturesPurged::class]);

    Feature::define('foo', fn () => true);
    Feature::define('bar', fn () => true);

    Feature::purge();

    Event::assertDispatchedTimes(AllFeaturesPurged::class, 1);
});

it('dispatches events when updating a scoped feature', function () {
    Event::fake([FeatureUpdated::class]);

    Feature::define('foo', fn () => false);

    Feature::for('tim')->activate('foo');

    Event::assertDispatchedTimes(FeatureUpdated::class, 1);
    Event::assertDispatched(function (FeatureUpdated $event) {
        return $event->feature === 'foo'
            && $event->scope === 'tim'
            && $event->value === true;
    });
});

it('dispatches events when updating a feature for all scopes', function () {
    Event::fake([FeatureUpdatedForAllScopes::class]);

    Feature::define('foo', fn () => false);

    Feature::activateForEveryone('foo', true);

    Event::assertDispatchedTimes(FeatureUpdatedForAllScopes::class, 1);
    Event::assertDispatched(function (FeatureUpdatedForAllScopes $event) {
        return $event->feature === 'foo'
            && $event->value === true;
    });
});

it('dispatches events when deleting a feature value', function () {
    Event::fake([FeatureDeleted::class]);

    Feature::define('foo', fn () => false);

    Feature::for('tim')->forget('foo');

    Event::assertDispatchedTimes(FeatureDeleted::class, 1);
    Event::assertDispatched(function (FeatureDeleted $event) {
        return $event->feature === 'foo'
            && $event->scope === 'tim';
    });
});

it('can use eloquent morph map for scope serialization', function () {
    $model = new User(['id' => 6]);
    Relation::morphMap([
        'user-morph' => $model::class,
    ]);
    $scopes = [];
    Feature::define('foo', fn () => true);

    Feature::useMorphMap();
    Feature::for($model)->active('foo');

    $this->assertDatabaseHas('features', [
        'name' => 'foo',
        'scope' => 'user-morph|6',
        'value' => 'true',
    ]);
});

class UnregisteredFeature
{
    public function __invoke()
    {
        return 'unregistered-value';
    }
}

class UnregisteredFeatureWithResolve
{
    public function resolve()
    {
        return 'unregistered-value.resolve';
    }
}

class UnregisteredFeatureWithName
{
    public $name = 'feature-name';

    public function __invoke()
    {
        return 'unregistered-value';
    }
}
