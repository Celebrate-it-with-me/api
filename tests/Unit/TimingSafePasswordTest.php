<?php

use App\Support\TimingSafePassword;
use Illuminate\Support\Facades\Hash;

it('builds the stand-in hash with the same algorithm and cost as a real hash', function () {
    $real = Hash::make('x');
    $dummy = TimingSafePassword::dummyHash();

    expect(Hash::info($dummy))->toBe(Hash::info($real))
        ->and(Hash::needsRehash($dummy))->toBeFalse()
        ->and(substr($dummy, 0, 7))->toBe(substr($real, 0, 7));
});

it('runs exactly one Hash::check whether or not the account exists', function () {
    Hash::shouldReceive('check')->twice()->andReturn(false);
    Hash::shouldReceive('make')->andReturn('$2y$04$' . str_repeat('a', 53));

    expect(TimingSafePassword::check('pw', null))->toBeFalse()
        ->and(TimingSafePassword::check('pw', Hash::make('real')))->toBeFalse();
});

it('treats a non-string password as empty', function () {
    expect(TimingSafePassword::check(['a'], null))->toBeFalse();
});
